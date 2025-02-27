<?php
require(AppSettings::srcPath().'/includes/connect.php');
require(AppSettings::srcPath().'/includes/get_session.php');

$action = "";
if (!empty($_REQUEST['action'])) $action = $_REQUEST['action'];

if ($thisuser && $app->synchronizer_ok($thisuser, $_REQUEST['synchronizer_token'])) {
	if ($action == "set_for_sale") {
		$io_id = (int) $_REQUEST['set_for_sale_io_id'];
		$amount_each = (float) $_REQUEST['set_for_sale_amount_each'];
		$quantity = (int) $_REQUEST['set_for_sale_quantity'];
		$game_id = (int) $_REQUEST['set_for_sale_game_id'];
		
		$db_game = $app->fetch_game_by_id($game_id);
		
		if ($db_game) {
			$sale_blockchain = new Blockchain($app, $db_game['blockchain_id']);
			$sale_game = new Game($sale_blockchain, $db_game['game_id']);
			$sale_currency_id = $sale_blockchain->currency_id();
			
			$satoshis_each = pow(10,$db_game['decimal_places'])*$amount_each;
			$fee_amount = (int) ($_REQUEST['set_for_sale_fee']*pow(10,$sale_blockchain->db_blockchain['decimal_places']));
			
			if ($quantity > 0 && $satoshis_each > 0) {
				$total_cost_satoshis = $quantity*$satoshis_each;
				
				$db_io = $app->fetch_io_by_id($io_id);
				
				if ($db_io) {
					$gios_by_io = $sale_game->fetch_game_ios_by_io($io_id)->fetchAll();
					
					if (count($gios_by_io) > 0) {
						$game_sale_account = $sale_game->check_set_game_sale_account($thisuser);
						
						$game_ios = [];
						$colored_coin_sum = 0;
						
						foreach ($gios_by_io as $game_io) {
							array_push($game_ios, $game_io);
							$colored_coin_sum += $game_io['colored_amount'];
						}
						
						$coin_sum = $game_ios[0]['amount'];
						$coins_per_chain_coin = (float) $colored_coin_sum/($coin_sum-$fee_amount);
						$chain_coins_each = ceil($satoshis_each/$coins_per_chain_coin);
						
						if (in_array($game_ios[0]['spend_status'], array("unspent", "unconfirmed"))) {
							$address_ids = [];
							$address_key_ids = [];
							$addresses_needed = $quantity;
							$loop_count = 0;
							do {
								$db_address = $app->new_normal_address_key($game_sale_account['currency_id'], $game_sale_account);
								
								array_push($address_ids, $db_address['address_id']);
								array_push($address_key_ids, $addr_key['address_key_id']);
								
								$addresses_needed--;
								$loop_count++;
							}
							while ($addresses_needed > 0 && $loop_count < $quantity*2);
							
							if ($addresses_needed > 0) {
								if (count($address_ids) > 0) {
									$app->run_query("UPDATE addresses SET user_id=NULL WHERE address_id IN (".implode(",", array_map("intval", $address_ids)).");");
									$app->run_query("UPDATE address_keys SET account_id=NULL WHERE address_key_id IN (".implode(",", array_map("intval", $address_key_ids)).");");
								}
								die("Not enough free addresses (still need $addresses_needed/$quantity).");
							}
							
							$account_q = "SELECT ca.* FROM currency_accounts ca JOIN games g ON g.game_id=ca.game_id JOIN address_keys k ON k.account_id=ca.account_id WHERE ca.user_id=:user_id AND k.address_id=:address_id;";
							$donate_account = $app->run_query($account_q, [
								'user_id' => $thisuser->db_user['user_id'],
								'address_id' => $game_ios[0]['address_id']
							])->fetch();
							
							if ($donate_account) {
								if ($total_cost_satoshis < $colored_coin_sum && $coin_sum > ($chain_coins_each*$quantity) - $fee_amount) {
									$remainder_satoshis = $coin_sum - ($chain_coins_each*$quantity) - $fee_amount;
									
									if ($remainder_satoshis < 15) {
										$fee_amount += $remainder_satoshis;
										$remainder_satoshis = 0;
									}
									
									$send_address_ids = [];
									$amounts = [];
									
									for ($i=0; $i<$quantity; $i++) {
										array_push($amounts, $chain_coins_each);
										array_push($send_address_ids, $address_ids[$i]);
									}
									if ($remainder_satoshis > 0) {
										$remainder_address_key = $app->new_normal_address_key($donate_account['currency_id'], $donate_account);
										array_push($amounts, $remainder_satoshis);
										array_push($send_address_ids, $remainder_address_key['address_id']);
									}
									
									$error_message = false;
									$transaction_id = $sale_game->blockchain->create_transaction('transaction', $amounts, false, array($game_ios[0]['io_id']), $send_address_ids, $fee_amount, $error_message);
									
									if ($transaction_id) {
										$transaction = $app->fetch_transaction_by_id($transaction_id);
										header("Location: /explorer/games/".$db_game['url_identifier']."/transactions/".$transaction['tx_hash']."/");
										die();
									}
									else echo "TX Error: ".$error_message.".<br/>\n";
								}
								else {
									echo "UTXO is only ".$sale_game->display_coins($colored_coin_sum)." but you tried to spend ".$sale_game->display_coins($total_cost_satoshis, false, true)."<br/>\n";
								}
							}
							else echo "You don't own this UTXO.<br/>\n";
						}
						else echo "Invalid UTXO.";
					}
					else echo "Invalid UTXO.";
				}
				else echo "Invalid UTXO ID.";
			}
			else echo "Invalid quantity or amount per UTXO.";
		}
		else echo "Invalid game ID.";
	}
	else if ($action == "donate_to_faucet") {
		$io_id = (int) $_REQUEST['account_io_id'];
		$amount_each = (float) $_REQUEST['donate_amount_each'];
		$utxos_each = (int) $_REQUEST['donate_utxos_each'];
		$quantity = (int) $_REQUEST['donate_quantity'];
		$game_id = (int) $_REQUEST['donate_game_id'];
		$tx_fee = (float) $_REQUEST['donate_tx_fee'];
		
		$db_game = $app->fetch_game_by_id($game_id);
		
		if ($db_game) {
			$donate_blockchain = new Blockchain($app, $db_game['blockchain_id']);
			$donate_game = new Game($donate_blockchain, $db_game['game_id']);
			
			$satoshis_each = pow(10,$db_game['decimal_places'])*$amount_each;
			$satoshis_each_utxo = ceil($satoshis_each/$utxos_each);
			$satoshis_each = $satoshis_each_utxo*$utxos_each;
			$fee_amount = (int)($tx_fee*pow(10,$donate_blockchain->db_blockchain['decimal_places']));
			
			if ($quantity > 0 && $satoshis_each > 0) {
				$total_cost_satoshis = $quantity*$satoshis_each;
				
				$db_io = $app->fetch_io_by_id($io_id);
				
				if ($db_io) {
					$gios_by_io = $donate_game->fetch_game_ios_by_io($io_id)->fetchAll();
					
					if (count($gios_by_io) > 0) {
						$faucet_account = $donate_game->check_set_faucet_account();
						
						$game_ios = [];
						$colored_coin_sum = 0;
						
						foreach ($gios_by_io as $game_io) {
							array_push($game_ios, $game_io);
							$colored_coin_sum += $game_io['colored_amount'];
						}
						
						$coin_sum = $game_ios[0]['amount'];
						$coins_per_chain_coin = (float) $colored_coin_sum/($coin_sum-$fee_amount);
						$chain_coins_each = ceil($satoshis_each_utxo/$coins_per_chain_coin);
						
						if (in_array($game_ios[0]['spend_status'], array("unspent", "unconfirmed"))) {
							$address_ids = [];
							$address_key_ids = [];
							$addresses_needed = $quantity;
							$loop_count = 0;
							
							do {
								$address_key = $app->new_normal_address_key($faucet_account['currency_id'], $faucet_account);
								
								array_push($address_ids, $address_key['address_id']);
								array_push($address_key_ids, $address_key_id);
								
								$addresses_needed--;
								$loop_count++;
							}
							while ($addresses_needed > 0);
							
							if ($addresses_needed > 0) {
								if (count($address_ids) > 0) {
									$app->run_query("UPDATE addresses SET user_id=NULL WHERE address_id IN (".implode(",", array_map("intval", $address_ids)).");");
									$app->run_query("UPDATE address_keys SET account_id=NULL WHERE address_key_id IN (".implode(",", array_map("intval", $address_key_ids)).");");
								}
								die("Not enough free addresses (still need $addresses_needed/$quantity).");
							}
							
							$account_q = "SELECT ca.* FROM currency_accounts ca JOIN games g ON g.game_id=ca.game_id JOIN address_keys k ON k.account_id=ca.account_id WHERE ca.user_id=:user_id AND k.address_id=:address_id;";
							$donate_account = $app->run_query($account_q, [
								'user_id' => $thisuser->db_user['user_id'],
								'address_id' => $game_ios[0]['address_id']
							])->fetch();
							
							if ($donate_account) {
								if ($total_cost_satoshis < $colored_coin_sum && $coin_sum > ($chain_coins_each*$quantity*$utxos_each) - $fee_amount) {
									$remainder_satoshis = $coin_sum - ($chain_coins_each*$quantity*$utxos_each) - $fee_amount;
									
									$send_address_ids = [];
									$amounts = [];
									
									for ($i=0; $i<$quantity; $i++) {
										for ($j=0; $j<$utxos_each; $j++) {
											array_push($amounts, $chain_coins_each);
											array_push($send_address_ids, $address_ids[$i]);
										}
									}
									if ($remainder_satoshis > 0) {
										$remainder_address_key = $app->new_normal_address_key($donate_account['currency_id'], $donate_account);
										array_push($amounts, $remainder_satoshis);
										array_push($send_address_ids, $remainder_address_key['address_id']);
									}
									
									$error_message = false;
									$transaction_id = $donate_game->blockchain->create_transaction('transaction', $amounts, false, array($game_ios[0]['io_id']), $send_address_ids, $fee_amount, $error_message);
									
									if ($transaction_id) {
										header("Location: /explorer/games/".$db_game['url_identifier']."/transactions/".$transaction_id."/");
										die();
									}
									else echo "TX Error: ".$error_message."<br/>\n";
								}
								else {
									echo "UTXO is only ".$donate_game->display_coins($colored_coin_sum)." but you tried to spend ".$donate_game->display_coins($total_cost_satoshis, false, true)."<br/>\n";
								}
							}
							else echo "You don't own this UTXO.<br/>\n";
						}
						else echo "Invalid UTXO.<br/>\n";
					}
					else echo "Invalid UTXO ID.<br/>\n";
				}
				else echo "Invalid UTXO ID.<br/>\n";
			}
			else echo "Invalid quantity.<br/>\n";
		}
		else echo "Invalid game ID.<br/>\n";
		die();
	}
	else if ($action == "set_target_balance") {
		$target_account = $app->fetch_account_by_id($_REQUEST['account_id']);
		
		if ($target_account['is_blockchain_sale_account'] && $app->user_is_admin($thisuser)) {
			$target_balance = (float) $_REQUEST['target_balance'];
			
			if ($target_balance >= 0) {
				if ($target_balance == 0) $target_balance = "";
				
				$app->set_target_balance($target_account['account_id'], $target_balance);
			}
		}
	}
}

$pagetitle = "My Accounts";
$nav_tab_selected = "accounts";
$nav_subtab_selected = "";

if (!empty($_REQUEST['redirect_key'])) $redirect_url = $app->get_redirect_by_key($_REQUEST['redirect_key']);

if (!empty($_REQUEST['account_id'])) {
	$selected_account_id = (int) $_REQUEST['account_id'];
	$selected_account = $app->fetch_account_by_id($selected_account_id);
	
	if (!empty($selected_account['game_id']) && $selected_account['user_id'] == $thisuser->db_user['user_id']) {
		$db_game = $app->fetch_game_by_id($selected_account['game_id']);
		$blockchain = new Blockchain($app, $db_game['blockchain_id']);
		$game = new Game($blockchain, $db_game['game_id']);
	}
	$selected_account = null;
}
else $selected_account_id = false;

include(AppSettings::srcPath().'/includes/html_start.php');
?>
<div class="container-fluid">
	<?php
	if ($thisuser) {
		?>
		<script type="text/javascript">
		thisPageManager.selected_account_id = <?php if ($selected_account_id) echo $selected_account_id; else echo 'false'; ?>;
		</script>
		
		<div class="panel panel-info" style="margin-top: 15px;">
			<div class="panel-heading">
				<?php
				$account_params = [
					'user_id' => $thisuser->db_user['user_id']
				];
				$account_q = "SELECT ca.*, c.*, b.url_identifier AS blockchain_url_identifier, k.pub_key, ug.user_game_id FROM currency_accounts ca JOIN currencies c ON ca.currency_id=c.currency_id JOIN blockchains b ON c.blockchain_id=b.blockchain_id LEFT JOIN addresses a ON ca.current_address_id=a.address_id LEFT JOIN address_keys k ON a.address_id=k.address_id LEFT JOIN user_games ug ON ug.account_id=ca.account_id WHERE ca.user_id=:user_id";
				if ($selected_account_id) {
					$account_q .= " AND ca.account_id=:account_id";
					$account_params['account_id'] = $selected_account_id;
				}
				$accounts = $app->run_query($account_q, $account_params)->fetchAll();
				
				$show_balances = false;
				if (count($accounts) <= 50 || !empty($_REQUEST['show_balances'])) $show_balances = true;
				
				if ($selected_account_id) {
					$show_balances = true;
					$selected_account = $accounts[0];
					$account_r = $app->run_query($account_q, $account_params);
					echo '
						<div class="panel-title">Account: '.$selected_account['account_name'].'</div>
					</div>
					<div class="panel-body">';
					
					echo '<p><a href="/accounts/">&larr; My Accounts</a></p>';
				}
				else {
					echo '
						<div class="panel-title">Coin Accounts</div>
					</div>
					<div class="panel-body">';
					
					echo "<p>You have ".count($accounts)." coin account";
					if (count($accounts) != 1) echo "s";
					echo ".</p>\n";
				}
				
				foreach ($accounts as $account) {
					$blockchain = new Blockchain($app, $account['blockchain_id']);
					$last_block_id = $blockchain->last_block_id();
					
					if ($account['game_id'] > 0) {
						$account_game = new Game($blockchain, $account['game_id']);
						if ($show_balances) {
							$game_confirmed_balance = $account_game->account_balance($account['account_id'], ['confirmed_only' => true]);
							$game_immature_balance = $account_game->account_balance($account['account_id'], ['include_immature' => 1]);
						}
					}
					else $account_game = false;
					
					if ($selected_account_id && $account_game) {
						echo '<p>';
						echo '<a href="/wallet/'.$account_game->db_game['url_identifier'].'/?action=change_user_game&user_game_id='.$account['user_game_id'].'" class="btn btn-sm btn-success">Play Now</a> ';
						echo '<a href="/explorer/games/'.$account_game->db_game['url_identifier'].'/my_bets/?user_game_id='.$account['user_game_id'].'" class="btn btn-sm btn-primary">My Bets</a>';
						echo '</p>';
					}
					
					echo '<div class="row">';
					echo '<div class="col-sm-4">';
					if (!$selected_account_id) echo '<a href="/accounts/?account_id='.$account['account_id'].'">';
					echo $account['account_name'];
					if (!$selected_account_id) echo '</a>';
					echo '</div>';
					
					if ($show_balances) {
						$mature_balance = $blockchain->account_balance($account['account_id']);
						$immature_balance = $blockchain->account_balance($account['account_id'], true);
						$immature_amount = $blockchain->account_balance($account['account_id'], false, true);
					}
					
					echo '<div class="col-sm-2" style="text-align: right">';
					if ($account['game_id'] > 0) {
						if ($show_balances) {
							echo '<font class="text-success">'.$account_game->display_coins($game_confirmed_balance).'</font>';
							
							if ($game_immature_balance != $game_confirmed_balance) {
								$game_immature_amount = $game_immature_balance - $game_confirmed_balance;
								echo ' &nbsp; <font class="text-warning">(+'.$account_game->display_coins($game_immature_amount, false, true).')</font>';
							}
						}
					}
					else echo "&nbsp;";
					echo '</div>';
					
					echo '<div class="col-sm-2" style="text-align: right">';
					if ($show_balances) {
						$ready_balance = $mature_balance - $immature_amount;
						$unready_balance = $immature_balance - $ready_balance;
						
						echo '<font class="text-success">'.$app->format_bignum($ready_balance/pow(10,$blockchain->db_blockchain['decimal_places'])).' '.$account['short_name_plural'].'</font>';
						
						if ($unready_balance > 0) {
							echo ' &nbsp; <font class="text-warning">(+'.$app->format_bignum($unready_balance/pow(10,$blockchain->db_blockchain['decimal_places'])).')</font>';
						}
					}
					echo '</div>';
					
					if ($selected_account_id) {
						echo '<div class="col-sm-2">';
						if (empty($account['game_id'])) {
							echo '<a href="" onclick="thisPageManager.toggle_account_details('.$account['account_id'].'); return false;">Deposit</a>';
							echo ' &nbsp;&nbsp; <a href="" onclick="thisPageManager.withdraw_from_account('.$account['account_id'].', 1); return false;">Withdraw</a>';
						}
						echo '</div>';
						
						echo '<div class="col-sm-2"><a href="" onclick="thisPageManager.toggle_account_details('.$account['account_id'].'); return false;">Transactions';
						
						$transaction_in_params = [
							'account_id' => $account['account_id']
						];
						$transaction_in_q = "SELECT * FROM transactions t JOIN transaction_ios io ON t.transaction_id=io.create_transaction_id JOIN addresses a ON a.address_id=io.address_id JOIN address_keys k ON a.address_id=k.address_id WHERE k.account_id=:account_id";
						if ($account['game_id'] > 0) {
							$transaction_in_q .= " AND t.blockchain_id=:blockchain_id";
							$transaction_in_params['blockchain_id'] = $blockchain->db_blockchain['blockchain_id'];
						}
						$transaction_in_q .= " ORDER BY (t.block_id IS NULL) DESC, t.block_id DESC LIMIT 200;";
						$transaction_in_arr = $app->run_query($transaction_in_q, $transaction_in_params)->fetchAll();
						
						$transaction_out_q = "SELECT * FROM transactions t JOIN transaction_ios io ON t.transaction_id=io.spend_transaction_id JOIN addresses a ON a.address_id=io.address_id JOIN address_keys k ON a.address_id=k.address_id WHERE k.account_id=:account_id";
						if ($account['game_id'] > 0) $transaction_out_q .= " AND t.blockchain_id=:blockchain_id";
						$transaction_out_q .= " ORDER BY (t.block_id IS NULL) DESC, t.block_id DESC LIMIT 200;";
						$transaction_out_arr = $app->run_query($transaction_out_q, $transaction_in_params)->fetchAll();
						
						echo ' ('.(count($transaction_in_arr)+count($transaction_out_arr)).')';
						
						echo '</a></div>';
						echo "</div>\n";
						
						echo '<div class="row" id="account_details_'.$account['account_id'].'"';
						if ($selected_account_id == $account['account_id']) {}
						else echo ' style="display: none;"';
						echo '>';

						echo "<div class=\"account_details\">";
						
						$account_selected_tab = isset($_REQUEST['selected_tab']) ? $_REQUEST['selected_tab'] : "";
						if (empty($account_selected_tab) && $selected_account_id == $account['account_id']) $account_selected_tab = "primary_address";
						
						echo '
						<ul class="nav nav-tabs">
							<li '.($account_selected_tab == "primary_address" ? 'class="active" ' : '').'role="presentation"><a data-toggle="tab" href="#primary_address_'.$account['account_id'].'">Deposit Address</a></li>
							<li '.($account_selected_tab == "transactions" ? 'class="active" ' : '').'role="presentation"><a data-toggle="tab" href="#transactions_'.$account['account_id'].'">Transactions</a></li>
							<li '.($account_selected_tab == "addresses" ? 'class="active" ' : '').'role="presentation"><a data-toggle="tab" href="#addresses_'.$account['account_id'].'">Addresses</a></li>';
							
							if ($app->user_is_admin($thisuser) && $account['is_blockchain_sale_account']) {
								echo '<li '.($account_selected_tab == "target" ? 'class="active" ' : '').'role="presentation"><a data-toggle="tab" href="#target_'.$account['account_id'].'">Target Balance</a></li>';
							}
						echo '</ul>';
						
						echo '
						<div class="tab-content" style="padding-top: 10px;">
							<div id="primary_address_'.$account['account_id'].'" class="tab-pane'.($account_selected_tab == "primary_address" ? ' active' : ' fade').'">';
						
						if (empty($account['pub_key'])) echo "<p>You haven't set a primary address for this account.</p>\n";
						else {
							echo "<p>You can deposit ".$account['short_name_plural'];
							if ($account_game) echo " or ".$account_game->db_game['coin_name_plural'];
							echo " to this account by sending to:</p>";
							echo '<a href="/explorer/blockchains/'.$blockchain->db_blockchain['url_identifier'].'/addresses/'.$account['pub_key'].'">'.$account['pub_key']."</a><br/>\n";
							echo '<img style="margin: 10px;" src="/render_qr_code.php?data='.$account['pub_key'].'" />';
						}
						
						echo "</div>\n";
						
						if ($app->user_is_admin($thisuser) && $account['is_blockchain_sale_account']) {
							echo '<div id="target_'.$account['account_id'].'" class="tab-pane'.($account_selected_tab == "target" ? ' active' : ' fade').'">';
							if ($selected_account_id) {
								?>
								<form method="post" action="/accounts/?account_id=<?php echo $account['account_id']; ?>">
									<input type="hidden" name="account_id" value="<?php echo $account['account_id']; ?>" />
									<input type="hidden" name="synchronizer_token" value="<?php echo $thisuser->get_synchronizer_token(); ?>" />
									<input type="hidden" name="action" value="set_target_balance" />
									<input type="hidden" name="selected_tab" value="target" />
									
									<div class="form-group">
										<label for="target_balance_<?php echo $account['account_id']; ?>">Target balance:</label>
										<div class="row">
											<div class="col-sm-4">
												<input type="text" name="target_balance" id="target_balance_<?php echo $account['account_id']; ?>" class="form-control" value="<?php echo $account['target_balance']; ?>" />
											</div>
											<div class="col-sm-8 form-control-static">
												<?php echo $blockchain->db_blockchain['coin_name_plural']; ?>
											</div>
										</div>
									</div>
									<input type="submit" class="btn btn-success" value="Save" />
								</form>
								<?php
							}
							echo "</div>\n";
						}
						
						echo '<div id="transactions_'.$account['account_id'].'" class="tab-pane'.($account_selected_tab == "transactions" ? ' active' : ' fade').'">';
						
						echo "<p>Rendering ".(count($transaction_in_arr) + count($transaction_out_arr))." transactions.</p>";
						
						foreach ($transaction_in_arr as $transaction) {
							if ($account_game) $colored_coin_amount = $account_game->game_amount_by_io($transaction['io_id']);
							
							echo '<div class="row">';
							echo '<div class="col-sm-4"><a href="/explorer/blockchains/'.$blockchain->db_blockchain['url_identifier'].'/addresses/'.$transaction['pub_key'].'">';
							echo $transaction['pub_key'];
							echo '</a></div>';
							
							if ($transaction['is_mature'] == 0 || $transaction['create_block_id'] == "") $io_render_class = "text-warning";
							else $io_render_class = "text-success";
							
							if ($account_game) {
								echo '<div class="col-sm-2" style="text-align: right;"><a class="'.$io_render_class.'" target="_blank" href="/explorer/games/'.$account_game->db_game['url_identifier'].'/transactions/'.$transaction['tx_hash'].'/">';
								echo "+".str_replace(" ", "&nbsp;", $account_game->display_coins($colored_coin_amount));
								echo '</a></div>';
							}
							
							echo '<div class="col-sm-2" style="text-align: right;"><a class="'.$io_render_class.'" target="_blank" href="/explorer/blockchains/'.$account['blockchain_url_identifier'].'/utxo/'.$transaction['tx_hash'].'/'.$transaction['out_index'].'">';
							echo "+".$app->format_bignum($transaction['amount']/pow(10,$blockchain->db_blockchain['decimal_places']))."&nbsp;".$account['short_name_plural'];
							echo '</a></div>';
							
							echo '<div class="col-sm-2">';
							if ($transaction['block_id'] == "") echo "<a target=\"_blank\" href=\"/explorer/blockchains/".$account['blockchain_url_identifier']."/transactions/unconfirmed/\">Not yet confirmed</a>";
							else echo "<a target=\"_blank\" href=\"/explorer/blockchains/".$account['blockchain_url_identifier']."/blocks/".$transaction['block_id']."\">Block&nbsp;#".$transaction['block_id']."</a>";
							echo "</div>\n";
							
							echo '<div class="col-sm-2">';
							
							if ($transaction['is_mature'] == 0) {
								$mature_on_block = $transaction['create_block_id'] + $blockchain->db_blockchain['coinbase_maturity'] - 1;
								$blocks_til_maturity = $mature_on_block - $last_block_id;
								echo 'Immature ('.$blocks_til_maturity.' block'.($blocks_til_maturity == 1 ? '' : 's').' left)';
							}
							else echo ucwords($transaction['spend_status']);
							
							if ($transaction['spend_status'] != "spent" && $transaction['is_mature']) {
								echo "&nbsp;&nbsp;<a href=\"\" onclick=\"thisPageManager.account_start_spend_io(";
								if ($account_game) echo $account_game->db_game['game_id'];
								else echo 'false';
								
								echo ', '.$transaction['io_id'].", ".($transaction['amount']/pow(10,$blockchain->db_blockchain['decimal_places'])).", '".$blockchain->db_blockchain['coin_name_plural']."', '";
								if ($account_game) echo $account_game->db_game['coin_name_plural'];
								echo "'); return false;\">Spend</a>";
							}
							echo '</div>';
							
							echo "</div>\n";
						}
						
						foreach ($transaction_out_arr as $transaction) {
							if ($account_game) $colored_coin_amount = $account_game->game_amount_by_io($transaction['io_id']);
							
							echo '<div class="row">';
							echo '<div class="col-sm-4"><a href="/explorer/blockchains/'.$blockchain->db_blockchain['url_identifier'].'/addresses/'.$transaction['pub_key'].'">';
							echo $transaction['pub_key'];
							echo '</a></div>';
							
							if ($account_game) {
								echo '<div class="col-sm-2" style="text-align: right;"><a class="redtext" target="_blank" href="/explorer/games/'.$account_game->db_game['url_identifier'].'/transactions/'.$transaction['tx_hash'].'">';
								echo "-".$account_game->display_coins($colored_coin_amount);
								echo '</a></div>';
							}
							
							echo '<div class="col-sm-2" style="text-align: right;"><a class="redtext" target="_blank" href="/explorer/blockchains/'.$account['blockchain_url_identifier'].'/transactions/'.$transaction['tx_hash'].'">';
							echo "-".$app->format_bignum($transaction['amount']/pow(10,$blockchain->db_blockchain['decimal_places']))."&nbsp;".$account['short_name_plural'];
							echo '</a></div>';
							
							echo '<div class="col-sm-2">';
							if ($transaction['block_id'] == "") echo "<a target=\"_blank\" href=\"/explorer/blockchains/".$account['blockchain_url_identifier']."/transactions/unconfirmed/\">Not yet confirmed</a>";
							else echo "<a target=\"_blank\" href=\"/explorer/blockchains/".$account['blockchain_url_identifier']."/blocks/".$transaction['block_id']."\">Block&nbsp;#".$transaction['block_id']."</a>";
							echo '</div>';
							
							echo '</div>';
						}
						
						echo '
							</div>
							<div id="addresses_'.$account['account_id'].'" class="tab-pane'.($account_selected_tab == "addresses" ? ' active' : ' fade').'">';
						$addr_arr = $app->run_query("SELECT * FROM addresses a JOIN address_keys k ON a.address_id=k.address_id WHERE k.account_id=:account_id ORDER BY a.option_index ASC LIMIT 500;", [
							'account_id' => $account['account_id']
						])->fetchAll();
						echo "<p>This account has ".count($addr_arr)." addresses.</p>";
						
						echo '<div style="max-height: 400px; overflow-x: hidden; overflow-y: scroll;">';
						
						foreach ($addr_arr as $address) {
							$address_balance = $blockchain->address_balance_at_block($address, false);
							if ($account_game) $game_balance = $account_game->address_balance_at_block($address, false);
							
							echo '<div class="row">';
							
							$balance_disp = $app->format_bignum($address_balance/pow(10, $blockchain->db_blockchain['decimal_places']));
							echo '<div class="col-sm-2">'.$balance_disp.' '.($balance_disp=="1" ? $blockchain->db_blockchain['coin_name'] : $blockchain->db_blockchain['coin_name_plural']).'</div>';
							
							if ($account_game) {
								echo '<div class="col-sm-2">'.$account_game->display_coins($game_balance).'</div>';
							}
							
							echo '<div class="col-sm-2">'.$address['vote_identifier'].' (#'.$address['option_index'].')';
							if ($address['is_destroy_address'] == 1) echo ' <font class="redtext">Destroy Address</font>';
							if ($address['is_separator_address'] == 1) echo ' <font class="yellowtext">Separator Address</font>';
							if ($address['is_passthrough_address'] == 1) echo ' <font class="yellowtext">Passthrough Address</font>';
							echo '</div>';
							
							echo '<div class="col-sm-4"><a href="/explorer/blockchains/'.$blockchain->db_blockchain['url_identifier'].'/addresses/'.$address['address'].'">'.$address['address'].'</a></div>';
							
							echo '<div class="col-sm-2">';
							if ($address['is_separator_address'] == 0 && $address['is_destroy_address'] == 0 && $address['is_passthrough_address'] == 0) {
								echo '<a href="" onclick="thisPageManager.manage_addresses('.$account['account_id'].', \'set_primary\', '.$address['address_id'].');">Set as Primary</a>';
							}
							echo '</div>';
							
							echo "</div>\n";
						}
						
						echo "</div>\n";
						
						echo '<br/><p><button class="btn btn-sm btn-primary" onclick="thisPageManager.manage_addresses('.$account['account_id'].', \'new\', false);">New Address</button></p>';
						echo '
							</div>
						</div>';
						
						echo "</div>\n";
					}
					echo "</div>\n";
				}
				?>
				<p style="margin-top: 10px;">
					<a href="" onclick="$('#create_account_dialog').toggle('fast'); return false;">Create a new account</a>
				</p>
				<div id="withdraw_dialog" class="modal fade" style="display: none;">
					<div class="modal-dialog">
						<div class="modal-content">
							<div class="modal-header">
								<h4 class="modal-title">Withdraw Coins</h4>
							</div>
							<div class="modal-body">
								<div class="form-group">
									<label for="withdraw_amount">Amount:</label>
									<input class="form-control" type="tel" placeholder="0.000" id="withdraw_amount" style="text-align: right;" />
								</div>
								<div class="form-group">
									<label for="withdraw_fee">Fee:</label>
									<input class="form-control" type="tel" placeholder="0.0001" id="withdraw_fee" style="text-align: right;" />
								</div>
								<div class="form-group">
									<label for="withdraw_address">Address:</label>
									<input class="form-control" type="text" id="withdraw_address" />
								</div>
								
								<div class="greentext" style="display: none;" id="withdraw_message"></div>
								
								<button id="withdraw_btn" class="btn btn-success" onclick="thisPageManager.withdraw_from_account(false, 2);">Withdraw</button>
							</div>
						</div>
					</div>
				</div>
				<div id="create_account_dialog" style="display: none;">
					<div class="form-group">
						<label for="create_account_action">Create a new account:</label>
						<select class="form-control" id="create_account_action" onchange="thisPageManager.create_account_step(1);">
							<option value="">-- Please Select --</option>
							<option value="for_blockchain">Create a new blockchain account</option>
						</select>
					</div>
					<div class="form-group" id="create_account_step2" style="display: none;">
						<label for="create_account_blockchain_id">Please select a blockchain:</label>
						<select class="form-control" id="create_account_blockchain_id" onchange="thisPageManager.create_account_step(2);">
							<option value="">-- Please Select --</option>
							<?php
							$all_blockchains = $app->run_query("SELECT * FROM blockchains ORDER BY blockchain_name ASC;")->fetchAll();
							foreach ($all_blockchains as $db_blockchain) {
								echo '<option value="'.$db_blockchain['blockchain_id'].'">'.$db_blockchain['blockchain_name'].'</option>'."\n";
							}
							?>
						</select>
					</div>
					<div class="form-group" id="create_account_step3" style="display: none;">
						<label for="create_account_rpc_name">Please enter the account name as used by the coin daemon:</label>
						<input type="text" class="form-control" id="create_account_rpc_name" value="" />
					</div>
					<div class="form-group" id="create_account_submit" style="display: none;">
						<button class="btn btn-primary" onclick="thisPageManager.create_account_step('submit');">Create Account</button>
					</div>
				</div>
			</div>
		</div>
		
		<div style="display: none;" class="modal fade" id="account_spend_modal">
			<div class="modal-dialog">
				<div class="modal-content">
					<div class="modal-header">
						<h4 class="modal-title" id="account_spend_modal_title">What do you want to do with these coins?</h4>
					</div>
					<div class="modal-body">
						<div class="form-group">
							<select class="form-control" id="account_spend_action" onchange="thisPageManager.account_spend_action_changed();">
								<option value="">-- Please select --</option>
								<option value="withdraw">Spend</option>
								<option value="split">Split into pieces</option>
								<option value="buyin">Buy in to a game</option>
								<option value="faucet">Donate to faucet</option>
								<option value="set_for_sale">Set as for sale</option>
								<option value="join_tx">Join with another UTXO</option>
							</select>
						</div>
						<div id="account_spend_join_tx" style="display: none;">
							Loading...
						</div>
						<div id="account_spend_withdraw" style="display: none;">
							<form onsubmit="thisPageManager.account_spend_withdraw(); return false;">
								<div class="form-group">
									<label for="spend_withdraw_address">Address:</label>
									<input type="text" class="form-control" id="spend_withdraw_address" />
								</div>
								<div class="form-group">
									<label for="spend_withdraw_amount">Amount:</label>
									<div class="row">
										<div class="col-sm-8"><input type="text" class="form-control" id="spend_withdraw_amount" style="text-align: right;" /></div>
										<div class="col-sm-4">
											<select class="form-control" id="spend_withdraw_coin_type" required="required"></select>
										</div>
									</div>
								</div>
								<div class="form-group">
									<label for="spend_withdraw_fee">Fee:</label>
									<div class="row">
										<div class="col-sm-8"><input type="text" class="form-control" id="spend_withdraw_fee" placeholder="0.0001" style="text-align: right;" /></div>
										<div class="col-sm-4 form-control-static" id="spend_withdraw_fee_label"></div>
									</div>
								</div>
								<div class="form-group">
									<button class="btn btn-primary">Withdraw</button>
								</div>
							</form>
						</div>
						<?php if ($account_game) { ?>
						<div id="account_spend_faucet" style="display: none;">
							<form action="/accounts/" method="get">
								<input type="hidden" name="action" value="donate_to_faucet" />
								<input type="hidden" name="donate_game_id" id="donate_game_id" value="" />
								<input type="hidden" name="account_io_id" id="account_io_id" value="" />
								<input type="hidden" name="synchronizer_token" value="<?php echo $thisuser->get_synchronizer_token(); ?>" />
								
								<div class="form-group">
									<label for="donate_amount_each">How many in-game coins should each person receive?</label>
									<input type="text" class="form-control" name="donate_amount_each" />
								</div>
								<div class="form-group">
									<label for="donate_amount_each">How many UTXOs should each person's coins be divided into?</label>
									<input type="text" class="form-control" name="donate_utxos_each" />
								</div>
								<div class="form-group">
									<label for="donate_quantity">How many faucet contributions do you want to make?</label>
									<input type="text" class="form-control" name="donate_quantity" />
								</div>
								<div class="form-group">
									<label for="donate_tx_fee">Transaction fee:</label>
									<input type="text" class="form-control" name="donate_tx_fee" value="<?php echo $account_game->db_game['default_transaction_fee']; ?>" />
								</div>
								<div class="form-group">
									<button class="btn btn-primary">Donate to Faucet</button>
								</div>
							</form>
						</div>
						<div id="account_spend_set_for_sale" style="display: none;">
							<form action="/accounts/" method="get">
								<input type="hidden" name="action" value="set_for_sale" />
								<input type="hidden" name="set_for_sale_game_id" id="set_for_sale_game_id" value="" />
								<input type="hidden" name="set_for_sale_io_id" id="set_for_sale_io_id" value="" />
								<input type="hidden" name="synchronizer_token" value="<?php echo $thisuser->get_synchronizer_token(); ?>" />
								
								<div class="form-group">
									<label for="set_for_sale_amount_each">How many in-game coins do you want to transfer to the sale account?</label>
									<input type="text" class="form-control" name="set_for_sale_amount_each" id="set_for_sale_amount_each" />
								</div>
								<div class="form-group" style="display: none;">
									<label for="set_for_sale_quantity">How many UTXOs do you want to make?</label>
									<input type="text" class="form-control" name="set_for_sale_quantity" id="set_for_sale_quantity" value="1" />
								</div>
								<div class="form-group">
									<label for="set_for_sale_fee">What fee do you want to pay to get this TX confirmed?</label>
									<input type="text" class="form-control" name="set_for_sale_fee" id="set_for_sale_fee" value="<?php echo $account_game->db_game['default_transaction_fee']; ?>" />
								</div>
								<div class="form-group">
									<button class="btn btn-success">Set for sale</button>
								</div>
							</form>
						</div>
						<div id="account_spend_split" style="display: none;" onsubmit="thisPageManager.account_spend_split(); return false;">
							<form action="/accounts/" method="get">
								<input type="hidden" name="action" value="split" />
								<input type="hidden" name="synchronizer_token" value="<?php echo $thisuser->get_synchronizer_token(); ?>" />
								
								<div class="form-group">
									<label for="split_amount_each">How many <?php echo $account_game->db_game['coin_name_plural']; ?> should be in each UTXO?</label>
									<input type="text" class="form-control" name="split_amount_each" id="split_amount_each" />
								</div>
								<div class="form-group">
									<label for="split_quantity">How many UTXOs do you want to make?</label>
									<input type="text" class="form-control" name="split_quantity" id="split_quantity" />
								</div>
								<div class="form-group">
									<label for="split_quantity">Transaction fee:</label>
									<div class="row">
										<div class="col-sm-8">
											<input type="text" class="form-control" name="split_fee" id="split_fee" placeholder="0.0001" />
										</div>
										<div class="col-sm-4 form-control-static">
											<?php if ($selected_account_id && !empty($blockchain)) echo $blockchain->db_blockchain['coin_name_plural']; ?>
										</div>
									</div>
								</div>
								<div class="form-group">
									<button class="btn btn-primary">Split my coins</button>
								</div>
							</form>
						</div>
						<?php } ?>
						<div id="account_spend_buyin" style="display: none;">
							<br/>
							<p>
								Which game do you want to buy in to?
							</p>
							<select class="form-control" id="account_spend_game_id">
								<option value="">-- Please select --</option>
								<?php
								$my_games = $app->run_query("SELECT * FROM user_games ug JOIN games g ON ug.game_id=g.game_id WHERE ug.user_id=:user_id GROUP BY g.game_id ORDER BY g.name ASC;", [
									'user_id' => $thisuser->db_user['user_id']
								])->fetchAll();
								foreach ($my_games as $db_game) {
									echo "<option value=\"".$db_game['game_id']."\">".$db_game['name']."</option>\n";
								}
								?>
							</select>
							<br/>
							<p>
								How much do you want to buy in for? <span id="account_spend_buyin_total"></span>
							</p>
							<div class="row">
								<div class="col-md-4">
									<input class="form-control" style="text-align: right;" type="text" id="account_spend_buyin_amount" placeholder="0.00" />
								</div>
								<div class="col-md-4 form-control-static">
									coins
								</div>
								<div class="col-md-4 form-control-static" id="account_spend_buyin_color_amount"></div>
							</div>
							<br/>
							<p>
								Transaction fee:
							</p>
							<div class="row">
								<div class="col-md-4">
									<input class="form-control" style="text-align: right;" type="text" id="account_spend_buyin_fee" value="0.0001" />
								</div>
								<div class="col-md-4 form-control-static">
									coins
								</div>
							</div>
							<br/>
							<p>
								Which address should colored coins be sent to?
							</p>
							<select class="form-control" id="account_spend_buyin_address_choice" onchange="thisPageManager.account_spend_buyin_address_choice_changed();">
								<option value="new">Create a new address for me</option>
								<option value="existing">Let me enter an address</option>
							</select>
							<div id="account_spend_buyin_address_existing" style="display: none;">
								<br/>
								<p>
									Please enter the address where colored coins should be deposited:
								</p>
								<input class="form-control" id="account_spend_buyin_address" />
							</div>
							<br/>
							<button class="btn btn-primary" onclick="thisPageManager.account_spend_buyin();">Buy in</button>
						</div>
					</div>
				</div>
			</div>
		</div>
		
		<script type="text/javascript">
		window.onload = function() {
			thisPageManager.account_spend_refresh();
			<?php
			if ($action == "prompt_game_buyin") {
				?>
				thisPageManager.account_start_spend_io(false, <?php echo ((int) $_REQUEST['io_id']); ?>, <?php echo ((float) $_REQUEST['amount']); ?>, '', '');
				$('#account_spend_action').val('buyin');
				thisPageManager.account_spend_action_changed();
				<?php
			}
			?>
		};
		</script>
		<?php
	}
	else {
		include(AppSettings::srcPath()."/includes/html_login.php");
	}
	?>
</div>
<?php
include(AppSettings::srcPath().'/includes/html_stop.php');
?>