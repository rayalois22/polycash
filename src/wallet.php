<?php
require(AppSettings::srcPath().'/includes/connect.php');
require(AppSettings::srcPath().'/includes/get_session.php');

$error_code = false;
$message = "";

if (!isset($_REQUEST['action'])) $_REQUEST['action'] = "";

if ($_REQUEST['action'] == "unsubscribe") {
	include(AppSettings::srcPath().'/includes/html_start.php');
	
	$delivery = $app->run_query("SELECT * FROM async_email_deliveries WHERE delivery_key=:delivery_key;", [
		'delivery_key' => $_REQUEST['delivery_key']
	])->fetch();
	
	if ($delivery) {
		$unsubscribe_q = "UPDATE users u JOIN user_games ug ON u.user_id=ug.user_id SET ug.notification_preference='none' WHERE u.notification_email=:email";
		$unsubscribe_params = [
			'email' => $delivery['to_email']
		];
		if ($game) {
			$unsubscribe_q .= " AND ug.game_id=:game_id";
			$unsubscribe_params['game_id'] = $game->db_game['game_id'];
		}
		
		$app->run_query($unsubscribe_q, $unsubscribe_params);
	}
	?>
	<div style="padding: 15px;">
		<p>
			You've been unsubscribed.<br/>
			You'll no longer receive these email notifications.<br/>
			If you want to start receiving notifications again, you can log in to your account and edit your settings.
		</p>
	</div>
	<?php
	include(AppSettings::srcPath().'/includes/html_stop.php');
	die();
}

if ($_REQUEST['action'] == "logout" && $thisuser) {
	$thisuser->log_out($session);
	
	$thisuser = FALSE;
	$message = "You have been logged out. ";
	header("Location: /wallet");
	die();
}

if (empty($thisuser) && !empty($_REQUEST['login_key'])) {
	$login_link_error = false;
	
	$login_link = $app->run_query("SELECT * FROM user_login_links WHERE access_key=:access_key;", [
		'access_key' => $_REQUEST['login_key']
	])->fetch();
	
	if ($login_link) {
		if (empty($login_link['time_clicked'])) {
			if ($login_link['time_created'] > time()-(60*15)) {
				if (empty($login_link['user_id'])) {
					$existing_user = $app->fetch_user_by_username($login_link['username']);
					
					if (!$existing_user) {
						$verify_code = $app->random_string(32);
						$salt = $app->random_string(16);
						
						$thisuser = $app->create_new_user($verify_code, $salt, $login_link['username'], "");
					}
					else {
						$login_link_error = true;
						$message = "Error: you followed an invalid login link. Please try again.";
					}
				}
				else {
					$db_user = $app->fetch_user_by_id($login_link['user_id']);
					
					if ($db_user) {
						$thisuser = new User($app, $db_user['user_id']);
					}
					else {
						$login_link_error = true;
						$message = "Error: invalid login link. Please try again.";
					}
				}
				
				if (!$login_link_error) {
					$app->run_query("UPDATE user_login_links SET time_clicked=:time_clicked WHERE login_link_id=:login_link_id;", [
						'time_clicked' => time(),
						'login_link_id' => $login_link['login_link_id']
					]);
					
					$redirect_url = false;
					$login_success = $thisuser->log_user_in($redirect_url, $viewer_id);
					
					if ($redirect_url) {
						header("Location: ".$redirect_url['url']);
						die();
					}
				}
			}
			else {
				$login_link_error = true;
				$message = "Error: this login link has already expired.";
			}
		}
		else {
			$login_link_error = true;
			$message = "Error: this login link has already been used.";
		}
	}
	else {
		$login_link_error = true;
		$message = "Please supply a valid login_key.";
	}
	
	if ($login_link_error) {
		?>
		<font class="redtext"><?php echo $message; ?></font>
		<?php
		die();
	}
}

$uri_parts = explode("/", $uri);
if (empty($uri_parts[2])) $requested_game = null;
else {
	$url_identifier = $uri_parts[2];
	$requested_game = $app->fetch_game_by_identifier($url_identifier);
}

if ($requested_game) {
	$blockchain = new Blockchain($app, $requested_game['blockchain_id']);
	$game = new Game($blockchain, $requested_game['game_id']);
}

if (!$thisuser) {
	if (!empty($_REQUEST['redirect_key'])) $redirect_url = $app->get_redirect_by_key($_REQUEST['redirect_key']);
	else {
		$uri = str_replace("?action=logout", "", $_SERVER['REQUEST_URI']);
		$redirect_url = $app->get_redirect_url($uri);
	}
	
	$nav_tab_selected = "wallet";
	include(AppSettings::srcPath().'/includes/html_start.php');
	
	?>
	<div class="container-fluid">
		<?php
		if ($game) {
			$top_nav_show_search = true;
			$explorer_type = "games";
			$explore_mode = "wallet";
			echo "<br/>\n";
			include('includes/explorer_top_nav.php');
		}
		
		include(AppSettings::srcPath()."/includes/html_login.php");
		?>
	</div>
	<?php
	include(AppSettings::srcPath().'/includes/html_stop.php');
	die();
}

if (!empty($_REQUEST['invite_key'])) {
	$invite_user_game = false;
	$invite_game = false;
	$success = $app->try_apply_invite_key($thisuser->db_user['user_id'], $_REQUEST['invite_key'], $invite_game, $invite_user_game);
	if ($success) {
		header("Location: /wallet/".$invite_game->db_game['url_identifier']);
		die();
	}
}

if (empty($game)) {
	$pagetitle = AppSettings::getParam('site_name')." - My web wallet";
	$nav_tab_selected = "wallet";
	include(AppSettings::srcPath().'/includes/html_start.php');
	?>
	<div class="container-fluid">
		<div class="panel panel-default" style="margin-top: 15px;">
			<?php
			$my_games = $app->my_games($thisuser->db_user['user_id'], false)->fetchAll();
			
			if (count($my_games) > 0) {
				?>
				<div class="panel-heading">
					<div class="panel-title">Please select a game:</div>
				</div>
				<div class="panel-body">
					<?php
					foreach ($my_games as $user_game) {
						echo "<a href=\"/wallet/".$user_game['url_identifier']."/\">".$user_game['name']."</a><br/>\n";
					}
					?>
				</div>
				<?php
			}
			else {
				?>
				<div class="panel-heading">
					<div class="panel-title">Please select a game.</div>
				</div>
				<div class="panel-body">
					You haven't joined any games yet.  <a href="/">Click here</a> to see a list of available games.
				</div>
				<?php
			}
			?>
		</div>
	</div>
	<?php
	include(AppSettings::srcPath().'/includes/html_stop.php');
	die();
}

if ($_REQUEST['action'] == "change_user_game") {
	$app->change_user_game($thisuser, $game, $_REQUEST['user_game_id']);
	
	header("Location: /wallet/".$game->db_game['url_identifier']."/");
	die();
}

$user_game = $thisuser->ensure_user_in_game($game, false);

if (($_REQUEST['action'] == "save_voting_strategy" || $_REQUEST['action'] == "save_voting_strategy_fees") && $app->synchronizer_ok($thisuser, $_REQUEST['synchronizer_token'])) {
	$voting_strategy = $_REQUEST['voting_strategy'];
	$voting_strategy_id = intval($_REQUEST['voting_strategy_id']);
	$api_url = "";
	if ($voting_strategy == "hit_url") $api_url = $app->strong_strip_tags($_REQUEST['hit_api_url']);
	
	if ($voting_strategy_id > 0) {
		$user_strategy = $app->fetch_strategy_by_id($voting_strategy_id);
		
		if (!$user_strategy || $user_strategy['user_id'] != $thisuser->db_user['user_id']) die("Invalid strategy ID");
	}
	else {
		$app->run_insert_query("user_strategies", [
			'user_id' => $thisuser->db_user['user_id'],
			'game_id' => $game->db_game['game_id']
		]);
		$voting_strategy_id = $app->last_insert_id();
		
		$user_strategy = $app->fetch_strategy_by_id($voting_strategy_id);
		
		$app->run_query("UPDATE user_games SET strategy_id=:strategy_id WHERE user_game_id=:user_game_id;", [
			'strategy_id' => $user_strategy['strategy_id'],
			'user_game_id' => $user_game['user_game_id'],
		]);
	}
	
	if ($_REQUEST['action'] == "save_voting_strategy_fees") {
		$transaction_fee = floatval($_REQUEST['transaction_fee']);
		
		$app->run_query("UPDATE user_strategies SET transaction_fee=:transaction_fee WHERE strategy_id=:strategy_id;", [
			'transaction_fee' => $transaction_fee,
			'strategy_id' => $user_strategy['strategy_id']
		]);
		$user_strategy['transaction_fee'] = $transaction_fee;
		
		$error_code = 1;
		$message = "Great, your transaction fee has been updated!";
	}
	else {
		if (in_array($voting_strategy, ['manual', 'api', 'by_plan', 'by_entity','hit_url','featured'])) {
			$update_strategy_params = [
				'voting_strategy' => $voting_strategy,
				'api_url' => $api_url,
				'strategy_id' => $user_strategy['strategy_id']
			];
			$update_strategy_q = "UPDATE user_strategies SET voting_strategy=:voting_strategy, api_url=:api_url WHERE strategy_id=:strategy_id;";
			$app->run_query($update_strategy_q, $update_strategy_params);
		}
	}
}

$pagetitle = $game->db_game['name']." - Wallet";

AppSettings::addJsDependency("jquery.nouislider.js");

$nav_tab_selected = "wallet";
include(AppSettings::srcPath().'/includes/html_start.php');

$initial_tab = 0;
if (!empty($_REQUEST['initial_tab'])) $initial_tab = (int) $_REQUEST['initial_tab'];

$last_block_id = $game->last_block_id();
$current_round = $game->block_to_round($last_block_id+1);
$block_within_round = $game->block_id_to_round_index($last_block_id+1);
$coins_per_vote = $app->coins_per_vote($game->db_game);

$unconfirmed_amount = $thisuser->unconfirmed_amount($game, $user_game);
$immature_amount = $thisuser->immature_amount($game, $user_game);
$mature_balance = $thisuser->mature_balance($game, $user_game);

list($user_votes, $votes_value) = $thisuser->user_current_votes($game, $last_block_id, $current_round, $user_game);
$user_pending_bets = $game->user_pending_bets($user_game);
$game_pending_bets = $game->pending_bets(true);
list($vote_supply, $vote_supply_value) = $game->vote_supply($last_block_id, $current_round, $coins_per_vote, true);
$account_value = $mature_balance+$immature_amount+$unconfirmed_amount+$user_pending_bets;

$blockchain_last_block_id = $game->blockchain->last_block_id();
$blockchain_current_round = $game->block_to_round($blockchain_last_block_id+1);
$blockchain_block_within_round = $game->block_id_to_round_index($blockchain_last_block_id+1);
$blockchain_last_block = $game->blockchain->fetch_block_by_id($blockchain_last_block_id);
?>
<div class="container-fluid">
	<?php
	if (!empty($message)) {
		echo '<font style="display: block; margin: 10px 0px;" class="';
		if ($error_code == 1) echo "greentext";
		else echo "redtext";
		echo '">';
		echo $message;
		echo "</font>\n";
	}
	
	$user_strategy = $game->fetch_user_strategy($user_game);
	
	$faucet_io = $game->check_faucet($user_game);
	
	$filter_arr['date'] = false;
	$event_ids = "";
	list($new_event_js, $new_event_html) = $game->new_event_js(0, $thisuser, $filter_arr, $event_ids, true);
	?>
	<script type="text/javascript">
	//<![CDATA[
	var current_tab = 0;
	
	games.push(new Game(thisPageManager, <?php
		echo $game->db_game['game_id'];
		echo ', '.$game->last_block_id();
		echo ', false';
		echo ', "'.$game->mature_io_ids_csv($user_game).'"';
		echo ', "'.$game->db_game['payout_weight'].'"';
		echo ', '.$game->db_game['round_length'];
		echo ', '.$user_strategy['transaction_fee'];
		echo ', "'.$game->db_game['url_identifier'].'"';
		echo ', "'.$game->db_game['coin_name'].'"';
		echo ', "'.$game->db_game['coin_name_plural'].'"';
		echo ', "'.$game->blockchain->db_blockchain['coin_name'].'"';
		echo ', "'.$game->blockchain->db_blockchain['coin_name_plural'].'"';
		echo ', "wallet", "'.$event_ids.'"';
		echo ', "'.$game->logo_image_url().'"';
		echo ', "'.$game->vote_effectiveness_function().'"';
		echo ', "'.$game->effectiveness_param1().'"';
		echo ', "'.$game->blockchain->db_blockchain['seconds_per_block'].'"';
		echo ', "'.$game->db_game['inflation'].'"';
		echo ', "'.$game->db_game['exponential_inflation_rate'].'"';
		echo ', "'.$blockchain_last_block['time_mined'].'"';
		echo ', "'.$game->db_game['decimal_places'].'"';
		echo ', "'.$game->blockchain->db_blockchain['decimal_places'].'"';
		echo ', "'.$game->db_game['view_mode'].'"';
		echo ', '.$user_game['event_index'];
		echo ', false';
		echo ', "'.$game->db_game['default_betting_mode'].'"';
		echo ', true, true, true';
	?>));
	
	<?php
	$load_event_rounds = 1;
	
	$plan_start_round = $current_round;
	$plan_stop_round = $plan_start_round+$load_event_rounds-1;
	
	$from_block_id = ($plan_start_round-1)*$game->db_game['round_length']+1;
	$to_block_id = ($plan_stop_round-1)*$game->db_game['round_length']+1;
	
	$initial_load_events = $app->run_query("SELECT * FROM events WHERE game_id=:game_id AND event_starting_block >= :from_block_id AND event_starting_block <= :to_block_id ORDER BY event_id ASC;", [
		'game_id' => $game->db_game['game_id'],
		'from_block_id' => $from_block_id,
		'to_block_id' => $to_block_id
	])->fetchAll();
	$num_initial_load_events = count($initial_load_events);
	$i=0;
	
	foreach ($initial_load_events as $db_event) {
		if ($i == 0) echo "games[0].all_events_start_index = ".$db_event['event_index'].";\n";
		else if ($i == $num_initial_load_events-1) echo "games[0].all_events_stop_index = ".$db_event['event_index'].";\n";
		
		echo "games[0].all_events[".$db_event['event_index']."] = new GameEvent(games[0], ".$i.", ".$db_event['event_id'].", ".$db_event['event_index'].", ".$db_event['num_options'].', "'.$db_event['vote_effectiveness_function'].'", "'.$db_event['effectiveness_param1'].'", '.$app->quote_escape($db_event['event_name']).", ".$db_event['event_starting_block'].", ".$db_event['event_final_block'].", ".$db_event['payout_rate'].");\n";
		echo "games[0].all_events_db_id_to_index[".$db_event['event_id']."] = ".$db_event['event_index'].";\n";
		
		$options_by_event = $app->fetch_options_by_event($db_event['event_id']);
		$j=0;
		while ($option = $options_by_event->fetch()) {
			$has_votingaddr = "true";
			echo "games[0].all_events[".$db_event['event_index']."].options.push(new EventOption(games[0].all_events[".$db_event['event_index']."], ".$j.", ".$option['option_id'].", ".$option['option_index'].", ".$app->quote_escape($option['name']).", 0, $has_votingaddr));\n";
			$j++;
		}
		$i++;
	}
	
	echo $game->load_all_event_points_js(0, $user_strategy, $plan_start_round, $plan_stop_round);
	?>
	window.onload = function() {
		thisPageManager.toggle_betting_mode('inflationary');
		thisPageManager.compose_bets_loop();
		<?php
		if (!$faucet_io) {
			if ($user_game['show_intro_message'] == 1) { ?>
				thisPageManager.show_intro_message();
				<?php
				$app->run_query("UPDATE user_games SET show_intro_message=0 WHERE user_game_id=:user_game_id;", ['user_game_id' => $user_game['user_game_id']]);
			}
			if ($user_game['prompt_notification_preference'] == 1) { ?>
				$('#notification_modal').modal('show');
				<?php
				$app->run_query("UPDATE user_games SET prompt_notification_preference=0 WHERE user_game_id=:user_game_id;", ['user_game_id' => $user_game['user_game_id']]);
			}
		}
		?>
		thisPageManager.render_tx_fee();
		thisPageManager.reload_compose_bets();
		thisPageManager.set_select_add_output();
		
		<?php
		if ($_REQUEST['action'] == "start_bet") {
			echo "games[0].add_option_to_vote(".((int)$_REQUEST['event_index']).", ".((int)$_REQUEST['option_id']).");\n";
		}
		?>
		thisPageManager.tab_clicked(0);
		thisPageManager.tab_clicked(<?php echo $initial_tab; ?>);
		
		thisPageManager.set_plan_rightclicks();
		thisPageManager.set_plan_round_sums();
		thisPageManager.render_plan_rounds();
	};
	
	//]]>
	</script>
	<?php
	$top_nav_show_search = true;
	$explorer_type = "games";
	$explore_mode = "wallet";
	echo "<br/>\n";
	include('includes/explorer_top_nav.php');
	?>
	<div class="panel panel-default" style="margin-top: 15px;">
		<div class="panel-heading">
			<div class="panel-title">
				<?php
				echo $game->db_game['name'];
				if ($game->db_game['game_status'] == "paused" || $game->db_game['game_status'] == "unstarted") echo " (Paused)";
				else if ($game->db_game['game_status'] == "completed") echo " (Completed)";
				?>
				<div style="float: right; display: inline-block; margin-top: -3px;">
					<div id="change_user_game">
						<select id="select_user_game" class="form-control input-sm" onchange="thisPageManager.change_user_game();">
							<?php
							$user_games_by_game = $app->run_query("SELECT * FROM user_games WHERE user_id=:user_id AND game_id=:game_id ORDER BY account_id ASC;", [
								'user_id' => $thisuser->db_user['user_id'],
								'game_id' => $game->db_game['game_id']
							])->fetchAll();
							if (count($user_games_by_game) <= 20) $user_game_show_balances = true;
							else $user_game_show_balances = false;
							
							foreach ($user_games_by_game as $db_user_game) {
								echo "<option ";
								if ($db_user_game['user_game_id'] == $user_game['user_game_id']) echo "selected=\"selected\" ";
								echo "value=\"".$db_user_game['user_game_id']."\">Account #".$db_user_game['account_id'];
								if ($user_game_show_balances) echo " &nbsp;&nbsp; ".$game->display_coins($game->account_balance($db_user_game['account_id'])+$game->user_pending_bets($db_user_game), true);
								echo "</option>\n";
							}
							?>
							<option value="new">Create a new account</option>
						</select>
					</div>
					<div style="float: right;">
						<select class="form-control input-sm" onchange="thisPageManager.change_game(this);">
							<option value="">-- Switch Games --</option>
							<?php
							$my_games = $app->my_games($thisuser->db_user['user_id'], true);
							
							while ($my_game = $my_games->fetch()) {
								echo "<option ";
								if ($game->db_game['game_id'] == $my_game['game_id']) echo 'selected="selected" ';
								echo "value=\"".$my_game['url_identifier']."\">".$my_game['name']."</option>\n";
							}
							?>
						</select>
					</div>
				</div>
			</div>
		</div>
		<div class="panel-body">
			<div style="display: none;" class="modal fade" id="game_invitations">
				<div class="modal-dialog">
					<div class="modal-content">
						<div class="modal-header">
							<b class="modal-title">Game Invitations</b>
							
							<button type="button" class="close" data-dismiss="modal" aria-label="Close">
								<span aria-hidden="true">&times;</span>
							</button>
						</div>
						<div id="game_invitations_inner">
						</div>
					</div>
				</div>
			</div>
			<div class="row">
				<div class="col-sm-6">
					<div class="row">
						<div class="col-sm-4">Account&nbsp;value:</div>
						<div class="col-sm-6" style="text-align: right;" id="account_value"><?php
						echo $game->account_value_html($account_value, $user_game, $game_pending_bets, $vote_supply_value);
						?></div>
					</div>
					<div id="wallet_text_stats" style="display: block;">
						<?php
						echo $thisuser->wallet_text_stats($game, $blockchain_current_round, $blockchain_last_block_id, $blockchain_block_within_round, $mature_balance, $unconfirmed_amount+$immature_amount, $user_votes, $votes_value, $user_pending_bets, $user_game);
						?>
					</div>
				</div>
				<?php
				if (!empty($game->db_game['wallet_promo_text'])) {
					?>
					<div class="col-sm-6">
						<div style="float: right; display: inline-block; margin-right: 20px;">
						<?php echo $game->db_game['wallet_promo_text']; ?>
						</div>
					</div>
					<?php
				}
				?>
			</div>
			<?php
			if ($game->db_game['buyin_policy'] != "none") { ?>
				<button class="btn btn-sm btn-success" style="margin-top: 8px;" onclick="thisPageManager.manage_buyin('initiate');"><i class="fas fa-arrow-down"></i> &nbsp; Deposit</button>
				<?php
			}
			if ($game->db_game['sellout_policy'] == "on") { ?>
				<button class="btn btn-sm btn-warning" style="margin-top: 8px;" onclick="thisPageManager.manage_sellout('initiate');"><i class="fas fa-arrow-up"></i> &nbsp; Withdraw</button>
				<?php
			}
			
			if (count($game->fetch_featured_strategies()->fetchAll()) > 0) {
				?>
				<button class="btn btn-sm btn-info" style="margin-top: 8px;" onclick="thisPageManager.apply_my_strategy();"><i class="fas fa-hand-point-up"></i> &nbsp; Apply my strategy now</button>
				<button class="btn btn-sm btn-danger" style="margin-top: 8px;" onclick="thisPageManager.show_featured_strategies(); return false;"><i class="fas fa-list"></i> &nbsp; Change my strategy</button>
				<?php
			}
			
			if ($game->db_game['faucet_policy'] == "on") { ?>
				<button class="btn btn-sm btn-default" style="margin-top: 8px;" onclick="thisPageManager.check_faucet(<?php echo $game->db_game['game_id']; ?>);"><i class="fas fa-tint"></i> &nbsp; Check Faucet</button>
				<?php
			}
			?>
			<div id="apply_my_strategy_status" class="greentext" style="margin-top: 10px; display: none;"></div>
		</div>
	</div>
	
	<div class="modal fade" id="faucet_info">
		<div class="modal-dialog">
			<div class="modal-content">
				<div class="modal-header">
					<b class="modal-title">Check the faucet for <?php echo $game->db_game['coin_name_plural']; ?></b>
					
					<button type="button" class="close" data-dismiss="modal" aria-label="Close">
						<span aria-hidden="true">&times;</span>
					</button>
				</div>
				<div class="modal-body" id="faucet_info_inner">
				</div>
			</div>
		</div>
	</div>
	
	<div id="tabcontent0" class="tabcontent">
		<div class="panel panel-default">
			<div class="panel-heading">
				<div class="panel-title" style="overflow: hidden; line-height: 200%;">
					Bet on upcoming <?php echo $game->db_game['event_type_name_plural']; ?>
					
					<div style="display: inline-block; float: right;">
						<div style="display: inline-block; float: right;">
							<select class="form-control input-sm" id="net_risk_view">
								<option value="0">Show each bet</option>
								<option value="1"<?php if ($user_game['net_risk_view']) echo ' selected="selected"'; ?>>Show net win by option</option>
							</select>
						</div>
					</div>
				</div>
			</div>
			<div class="panel-body">
				<?php
				if ($faucet_io) {
					echo '<p><button id="faucet_btn" class="btn btn-success" onclick="thisPageManager.claim_from_faucet();"><i class="fas fa-hand-paper"></i> &nbsp; Claim '.$game->display_coins($faucet_io['colored_amount_sum']).'</button></p>'."\n";
				}
				
				$game_status_explanation = $game->game_status_explanation($thisuser, $user_game);
				?>
				<div style="display: <?php if (false && $game->db_game['view_mode'] == "simple") echo "none"; else echo "block"; ?>; overflow: hidden;">
					<div id="game_status_explanation"<?php if ($game_status_explanation == "") echo ' style="display: none;"'; ?>><?php if ($game_status_explanation != "") echo $game_status_explanation; ?></div>
				</div>
				<?php
				if ($game->db_game['module'] == "CoinBattles") {
					$game->load_current_events();
					$event = $game->current_events[0];
					
					if (empty($event)) {
						echo "Chart canceled; there is no current event for this game.<br/>\n";
					}
					else {
						list($html, $js) = $game->module->currency_chart($game, $event->db_event['event_starting_block'], false);
						echo '<div style="margin-bottom: 15px;" id="game0_chart_html">'.$html."</div>\n";
						echo '<div id="game0_chart_js"><script type="text/javascript">'.$js.'</script></div>'."\n";
					}
				}
				?>
				
				<div class="row">
					<div class="col-md-6">
						<div style="overflow: auto; margin-bottom: 10px;">
							<div style="float: right;">
								<?php
								echo $game->event_filter_html();
								?>
							</div>
						</div>
						<div class="game_events game_events_long">
							<div id="game0_events" class="game_events_inner"><?php echo $new_event_html; ?></div>
						</div>
						
						<script type="text/javascript">
						<?php
						echo $new_event_js;
						?>
						</script>
						
						<?php echo $app->render_view('event_details_modal'); ?>
					</div>
					<div class="col-md-6">
						<div id="betting_mode_inflationary" style="display: none;">
							<p style="float: right; clear: both;"><a href="" onclick="thisPageManager.toggle_betting_mode('principal'); return false;">Switch to single betting mode</a></p>
							
							<p>
								<a href="/explorer/games/<?php echo $game->db_game['url_identifier']; ?>/my_bets/">My Bets</a>
							</p>
							
							<p>
								To start a bet, click on your coins below.
							</p>
							
							<div id="select_input_buttons" class="input_buttons_holder"><?php
								echo $game->select_input_buttons($user_game);
							?></div>
							
							<p>
								<a href="" onclick="thisPageManager.add_all_utxos_to_vote(); return false;">Add all coins</a>
								&nbsp;&nbsp; <a href="" onclick="thisPageManager.remove_all_utxos_from_vote(); return false;">Remove all coins</a>
							</p>
							
							<div id="compose_bets" style="display: none;">
								<h3>Stake Now</h3>
								<div class="row bordered_row" style="border: 1px solid #bbb;">
									<div class="col-md-4 bordered_cell" id="compose_bet_inputs">
										<b>Inputs:</b><div style="display: none; margin-left: 20px;" id="input_amount_sum"></div><div style="display: inline-block; margin-left: 20px;" id="input_vote_sum"></div><br/>
										<p>
											How many <?php echo $game->db_game['coin_name_plural']; ?> do you want to bet?
											<input class="form-control input-sm" id="compose_burn_amount" placeholder="0" /><font id="max_burn_amount"></font>
										</p>
										<p id="compose_input_start_msg"></p>
									</div>
									<div class="col-md-8 bordered_cell" id="compose_bet_outputs">
										<b>Outputs:</b>
										<div id="display_tx_fee"></div>
										&nbsp;&nbsp; <a href="" onclick="thisPageManager.add_all_options(); return false;">Add all options</a>
										&nbsp;&nbsp; <a href="" onclick="thisPageManager.remove_all_outputs(); return false;">Remove all options</a>
										
										<select class="form-control" style="margin-top: 5px;" id="select_add_output" onchange="thisPageManager.select_add_output_changed();"></select>
									</div>
								</div>
								<button class="btn btn-success" id="confirm_compose_bets_btn" style="margin-top: 5px; margin-left: 5px;" onclick="thisPageManager.confirm_compose_bets();"><i class="fas fa-check-circle"></i> &nbsp; Confirm & Stake</button>
							</div>
							
							<div class="redtext" id="compose_bets_errors" style="margin-top: 10px;"></div>
							<div class="greentext" id="compose_bets_success" style="margin-top: 10px;"></div>
						</div>
						<div id="betting_mode_principal" style="display: none;">
							<p style="float: right;"><a href="" onclick="thisPageManager.toggle_betting_mode('inflationary'); return false;">Switch to multiple betting mode</a></p>
							
							<a href="/explorer/games/<?php echo $game->db_game['url_identifier']; ?>/my_bets/">My Bets</a>
							
							<form method="get" onsubmit="thisPageManager.submit_principal_bet(); return false;" style="clear: both;">
								<div class="form-group">
									<label for="principal_amount">How much do you want to bet?</label>
									<div class="row">
										<div class="col-sm-6">
											<input class="form-control" type="text" id="principal_amount" name="principal_amount" style="text-align: right;" />
										</div>
										<div class="col-sm-6 form-control-static">
											<?php echo $game->db_game['coin_name_plural']; ?>
										</div>
									</div>
								</div>
								<div class="form-group">
									<label for="principal_option_id">Which option do you want to bet for?</label>
									<div class="row">
										<div class="col-sm-6">
											<select class="form-control" id="principal_option_id" name="principal_option_id"></select>
										</div>
									</div>
								</div>
								<div class="form-group">
									<label for="principal_fee">Transaction fee:</label>
									<div class="row">
										<div class="col-sm-6">
											<input class="form-control" type="text" id="principal_fee" name="principal_fee" style="text-align: right;" value="<?php echo rtrim($user_strategy['transaction_fee'], "0"); ?>" />
										</div>
										<div class="col-sm-6 form-control-static">
											<?php echo $game->blockchain->db_blockchain['coin_name_plural']; ?>
										</div>
									</div>
								</div>
								<div class="form-group">
									<button class="btn btn-success" id="principal_bet_btn"><i class="fas fa-check-circle"></i> &nbsp; Confirm Bet</button>
									<div id="principal_bet_message" class="greentext" style="margin-top: 10px;"></div>
								</div>
							</form>
						</div>
					</div>
				</div>
			</div>
		</div>
		
		<div id="game0_events_being_determined" style="display: none;"></div>
	</div>
	<?php if ($game->db_game['public_players'] == 1) { ?>
	<div class="tabcontent" style="display: none;" id="tabcontent1">
		<div class="panel panel-default">
			<div class="panel-heading">
				<div class="panel-title">Play Now</div>
			</div>
			<div class="panel-body">
				<?php
				echo $game->render_game_players();
				?>
			</div>
		</div>
	</div>
	<?php } ?>
	<div id="tabcontent2" style="display: none;" class="tabcontent">
		<div class="panel panel-default">
			<div class="panel-heading">
				<div class="panel-title">Settings</div>
			</div>
			<div class="panel-body">
				<h3>Transaction Fees</h3>
				<form method="post" action="/wallet/<?php echo $game->db_game['url_identifier']; ?>/">
					<input type="hidden" name="action" value="save_voting_strategy_fees" />
					<input type="hidden" name="voting_strategy_id" value="<?php echo $user_strategy['strategy_id']; ?>" />
					<input type="hidden" name="synchronizer_token" value="<?php echo $thisuser->get_synchronizer_token(); ?>" />
					
					Pay fees on every transaction of:<br/>
					<div class="row">
						<div class="col-sm-4"><input class="form-control" name="transaction_fee" value="<?php echo $app->format_bignum($user_strategy['transaction_fee']); ?>" placeholder="<?php echo $game->db_game['default_transaction_fee']; ?>" /></div>
						<div class="col-sm-4 form-control-static"><?php
						echo $game->blockchain->db_blockchain['coin_name_plural'];
						?></div>
					</div>
					<div class="row">
						<div class="col-sm-3">
							<button class="btn btn-sm btn-success" type="submit"><i class="fas fa-check-circle"></i> &nbsp; Save</button>
						</div>
					</div>
				</form>
				<br/>
				
				<h3>Notifications</h3>
				<button class="btn btn-sm btn-primary" onclick="$('#notification_modal').modal('show');"><i class="fas fa-envelope"></i> &nbsp; Notification Settings</button>
				<br/>
				<br/>
				
				<h3>Choose your strategy</h3>
				<p>
					Select a staking strategy and your coins will automatically be staked even when you're not online.
				</p>
				
				<form method="post" action="/wallet/<?php echo $game->db_game['url_identifier']; ?>/">
					<input type="hidden" name="action" value="save_voting_strategy" />
					<input type="hidden" id="voting_strategy_id" name="voting_strategy_id" value="<?php echo $user_strategy['strategy_id']; ?>" />
					<input type="hidden" name="synchronizer_token" value="<?php echo $thisuser->get_synchronizer_token(); ?>" />
					
					<div class="row bordered_row">
						<div class="col-md-2">
							<input type="radio" id="voting_strategy_manual" name="voting_strategy" value="manual"<?php if ($user_strategy['voting_strategy'] == "manual") echo ' checked="checked"'; ?>><label class="plainlabel" for="voting_strategy_manual">&nbsp;No&nbsp;auto-strategy</label>
						</div>
						<div class="col-md-10">
							<label class="plainlabel" for="voting_strategy_manual"> 
								I'll log in and vote in each round.
							</label>
						</div>
					</div>
					
					<div class="row bordered_row">
						<div class="col-md-2">
							<input type="radio" id="hit_api_url" name="voting_strategy" value="hit_url"<?php if ($user_strategy['voting_strategy'] == "hit_url") echo ' checked="checked"'; ?>><label class="plainlabel" for="hit_api_url">&nbsp;Hit URL</label>
						</div>
						<div class="col-md-10">
							<label class="plainlabel" for="hit_api_url">Hit this URL every minute</label>
							<input class="form-control" type="text" size="40" placeholder="http://" name="hit_api_url" id="hit_api_url" value="<?php echo $user_strategy['api_url']; ?>" />
						</div>
					</div>
					
					<div class="row bordered_row">
						<div class="col-md-2">
							<input type="radio" id="voting_strategy_by_plan" name="voting_strategy" value="by_plan"<?php if ($user_strategy['voting_strategy'] == "by_plan") echo ' checked="checked"'; ?>><label class="plainlabel" for="voting_strategy_by_plan">&nbsp;Plan&nbsp;my&nbsp;votes</label>
						</div>
						<div class="col-md-10">
							<button class="btn btn-sm btn-primary" onclick="thisPageManager.show_planned_votes(); return false;"><i class="fas fa-th"></i> &nbsp; Edit my planned votes</button>
						</div>
					</div>
					
					<div class="row bordered_row">
						<div class="col-md-2">
							<input type="radio" id="voting_strategy_featured" name="voting_strategy" value="featured"<?php if ($user_strategy['voting_strategy'] == "featured") echo ' checked="checked"'; ?>><label class="plainlabel" for="voting_strategy_featured">&nbsp;Choose a strategy</label>
						</div>
						<div class="col-md-10">
							<button class="btn btn-sm btn-primary" onclick="thisPageManager.show_featured_strategies(); return false;"><i class="fas fa-list"></i> &nbsp; Choose a strategy</button>
						</div>
					</div>
					<br/>
					<button class="btn btn-sm btn-success" type="submit"><i class="fas fa-check-circle"></i> &nbsp; Save my Strategy</button>
				</form>
				<br/>
			</div>
		</div>
	</div>
	<div id="tabcontent4" style="display: none;" class="tabcontent">
		<div class="panel panel-default">
			<div class="panel-heading">
				<div class="panel-title">Send &amp; receive <?php echo $game->db_game['coin_name_plural']; ?></div>
			</div>
			<div class="panel-body">
				<div class="row">
					<div class="col-sm-6">
						<p><b>Receive <?php echo $game->db_game['coin_name_plural']; ?></b></p>
						<p>
							<?php
							$game_account = $app->fetch_account_by_id($user_game['account_id']);
							
							if (!empty($game_account['current_address_id'])) {
								$default_address = $app->fetch_address_by_id($game_account['current_address_id']);
								
								echo "<p>You can receive ".$game->db_game['coin_name_plural']." with this address:</p>\n";
								echo "<p>".$default_address['address']."</p>\n";
								
								echo '<img style="margin: 10px;" src="/render_qr_code.php?data='.$default_address['address'].'" />';
							}
							?>
						</p>
						<p>
							To see all of your addresses visit <a href="/accounts/?account_id=<?php echo $user_game['account_id']; ?>">My Accounts</a>.
						</p>
					</div>
					<div class="col-sm-6">
						<p><b>Send <?php echo $game->db_game['coin_name_plural']; ?></b></p>
						
						<p>To withdraw <?php echo $game->db_game['coin_name_plural']; ?> enter <?php echo $app->prepend_a_or_an($game->db_game['name']); ?> address below.</p>
						
						<div class="row">
							<div class="col-md-6">
								<div class="form-group">
									<label for="withdraw_amount">Amount (<?php echo $game->db_game['coin_name_plural']; ?>):</label>
									<input class="form-control" type="tel" placeholder="0.000" id="withdraw_amount" style="text-align: right;" />
								</div>
								<div class="form-group">
									<label for="withdraw_fee">Fee (<?php echo $game->blockchain->db_blockchain['coin_name_plural']; ?>):</label>
									<input class="form-control" type="tel" value="<?php echo $app->format_bignum($user_strategy['transaction_fee']); ?>" id="withdraw_fee" style="text-align: right;" />
								</div>
								<div class="form-group">
									<label for="withdraw_address">Address:</label>
									<input class="form-control" type="text" id="withdraw_address" />
								</div>
								<div class="form-group">
									<button class="btn btn-sm btn-success" id="withdraw_btn" onclick="thisPageManager.attempt_withdrawal();"><i class="fas fa-arrow-circle-right"></i> &nbsp; Send <?php echo $game->db_game['coin_name_plural']; ?></button>
									<div id="withdraw_message" style="display: none; margin-top: 15px;"></div>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
	
	<div class="tabcontent" style="display: none;" id="tabcontent5">
		<div class="panel panel-default">
			<div class="panel-heading">
				<div class="panel-title">Invitations</div>
			</div>
			<div class="panel-body">
				<?php
				$perm_to_invite = $thisuser->user_can_invite_game($user_game);
				if ($perm_to_invite) {
					?>
					<a class="btn btn-sm btn-primary" href="" onclick="thisPageManager.manage_game_invitations(<?php echo $game->db_game['game_id']; ?>); return false;"><i class="fas fa-share"></i> &nbsp; Invitations</a>
					<?php
				}
				else echo "Sorry, you don't have permission to send invitations for this game.";
				?>
			</div>
		</div>
	</div>
	
	<div class="modal fade" id="intro_message">
		<div class="modal-dialog">
			<div class="modal-content">
				<div class="modal-header">
					<b class="modal-title">New message from <?php echo AppSettings::getParam('site_name'); ?></b>
					
					<button type="button" class="close" data-dismiss="modal" aria-label="Close">
						<span aria-hidden="true">&times;</span>
					</button>
				</div>
				<div class="modal-body">
					<p>
						Hi <?php echo $thisuser->db_user['username']; ?>, thanks for joining <?php echo $game->db_game['name']; ?>!
					</p>
					<p>
						It's recommended that you select an auto strategy so that your account will gain value while you sleep. You can change your auto strategy at any time by logging in and clicking the "Settings" tab to the left.
					</p>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-sm btn-warning" data-dismiss="modal"><i class="fas fa-times"></i> &nbsp; Close</button>
					 &nbsp;&nbsp;or&nbsp;&nbsp;
					<button class="btn btn-sm btn-primary" onclick="$('#intro_message').modal('hide'); thisPageManager.show_featured_strategies();"><i class="fas fa-list"></i> &nbsp; Choose an auto-strategy</button>
				</div>
			</div>
		</div>
	</div>
	
	<div class="modal fade" id="notification_modal">
		<div class="modal-dialog">
			<div class="modal-content">
				<div class="modal-header">
					<b class="modal-title">Notification Settings</b>
					
					<button type="button" class="close" data-dismiss="modal" aria-label="Close">
						<span aria-hidden="true">&times;</span>
					</button>
				</div>
				<form method="post" onsubmit="thisPageManager.save_notification_preferences(); return false;">
					<div class="modal-body">
						<div class="form-group">
							<label for="notification_preference">Would you like to receive notifications about the performance of your accounts?</label>
							<select class="form-control" id="notification_preference" name="notification_preference" onchange="thisPageManager.notification_pref_changed();" required="true">
								<option value="">-- Please Select --</option>
								<option <?php if ($user_game['notification_preference'] == "none") echo 'selected="selected" '; ?>value="none">No, don't notify me</option>
								<option <?php if ($user_game['notification_preference'] == "email") echo 'selected="selected" '; ?>value="email">Yes, send me email notifications</option>
							</select>
						</div>
						<div class="form-group">
							<input <?php if ($user_game['notification_preference'] == "none") echo 'style="display: none;" '; ?>class="form-control" type="text" name="notification_email" id="notification_email" placeholder="Enter your email address" value="<?php echo $thisuser->db_user['notification_email']; ?>" />
						</div>
						<p class="text-success" id="notification_modal_message" style="margin-top: 10px;"></p>
					</div>
					<div class="modal-footer">
						<button type="button" class="btn btn-sm btn-warning" data-dismiss="modal"><i class="fas fa-times"></i> &nbsp; Close</button>
						 &nbsp;&nbsp;or&nbsp;&nbsp; 
						<button type="submit" id="notification_save_btn" class="btn btn-sm btn-success"><i class="fas fa-check-circle"></i> &nbsp; Save Notification Settings</button>
					</div>
				</form>
			</div>
		</div>
	</div>
	
	<div style="display: none;" class="modal fade" id="featured_strategies">
		<div class="modal-dialog">
			<div class="modal-content">
				<div class="modal-header">
					<b class="modal-title">Please select a staking strategy</b>
					
					<button type="button" class="close" data-dismiss="modal" aria-label="Close">
						<span aria-hidden="true">&times;</span>
					</button>
				</div>
				<div id="featured_strategies_inner"></div>
			</div>
		</div>
	</div>
	
	<div style="display: none;" class="modal fade" id="planned_votes">
		<div class="modal-dialog" style="width: 80%; max-width: 1000px;">
			<div class="modal-content">
				<div class="modal-header">
					<b class="modal-title">My Planned Votes</b>
					
					<button type="button" class="close" data-dismiss="modal" aria-label="Close">
						<span aria-hidden="true">&times;</span>
					</button>
				</div>
				<div class="modal-body">
					<p>
						Set your planned votes by clicking on the options below.  You can vote on more than one option in each round. Keep clicking on an option to increase its votes.  Or right click to remove all votes from an option.  Your planned votes are confidential and cannot be seen by other players.
					</p>
					
					<button id="scramble_plan_btn" class="btn btn-sm btn-danger" onclick="thisPageManager.scramble_strategy(<?php echo $user_strategy['strategy_id']; ?>); return false;"><i class="fas fa-asterisk"></i> &nbsp; Randomize my Votes</button>
					
					<font style="margin-left: 25px;">Load rounds: </font><input type="text" size="5" id="select_from_round" value="<?php echo $game->round_to_display_round($plan_start_round); ?>" /> to <input type="text" size="5" id="select_to_round" value="<?php echo $game->round_to_display_round($plan_stop_round); ?>" /> <button class="btn btn-primary btn-sm" onclick="thisPageManager.load_plan_rounds(); return false;"><i class="fas fa-arrow-right"></i> &nbsp; Go</button>
					
					<br/>
					<div id="plan_rows" style="margin: 10px 0px; max-height: 350px; overflow-y: scroll; border: 1px solid #bbb; padding: 0px 10px;">
						<?php
						echo $game->plan_options_html($plan_start_round, $plan_stop_round, $user_strategy);
						?>
					</div>
					
					<div id="plan_rows_js"></div>
					
					<input type="hidden" id="from_round" name="from_round" value="<?php echo $game->round_to_display_round($plan_start_round); ?>" />
					<input type="hidden" id="to_round" name="to_round" value="<?php echo $game->round_to_display_round($plan_stop_round); ?>" />
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-sm btn-warning" data-dismiss="modal"><i class="fas fa-times"></i> &nbsp; Close</button>
					 &nbsp;&nbsp;or&nbsp;&nbsp; 
					<button id="save_plan_btn" class="btn btn-sm btn-success" onclick="thisPageManager.save_plan_allocations(); return false;"><i class="fas fa-check-circle"></i> &nbsp; Save Changes</button>
				</div>
			</div>
		</div>
	</div>
	
	<div style="display: none;" class="modal fade" id="set_event_outcome_modal">
		<div class="modal-dialog">
			<div class="modal-content" id="set_event_outcome_modal_content"></div>
		</div>
	</div>
	
	<div style="display: none;" class="modal fade" id="buyin_modal">
		<div class="modal-dialog modal-lg">
			<div class="modal-content">
				<div class="modal-header">
					<b class="modal-title"><?php echo $game->db_game['name']; ?>: Get <?php echo $game->db_game['coin_name_plural']; ?></b>
					
					<button type="button" class="close" data-dismiss="modal" aria-label="Close">
						<span aria-hidden="true">&times;</span>
					</button>
				</div>
				<div class="modal-body">
					<div id="buyin_modal_content"></div>
					<div id="buyin_modal_details" style="margin-top: 10px;"></div>
					<div id="buyin_modal_invoices"></div>
				</div>
			</div>
		</div>
	</div>
	
	<div style="display: none;" class="modal fade" id="sellout_modal">
		<div class="modal-dialog modal-lg">
			<div class="modal-content">
				<div class="modal-header">
					<b class="modal-title"><?php echo $game->db_game['name']; ?>: Exchange &amp; withdraw</b>
					
					<button type="button" class="close" data-dismiss="modal" aria-label="Close">
						<span aria-hidden="true">&times;</span>
					</button>
				</div>
				<div class="modal-body">
					<div id="sellout_modal_content"></div>
					<div id="sellout_modal_details" style="margin-top: 10px;"></div>
					<div id="sellout_modal_invoices"></div>
				</div>
			</div>
		</div>
	</div>
	
	<br/><br/>
</div>
<?php
include(AppSettings::srcPath().'/includes/html_stop.php');
?>
