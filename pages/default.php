<?php
$pagetitle = $GLOBALS['coin_brand_name']." - Home";
$nav_tab_selected = "home";
include('includes/html_start.php');
?>
<div class="container-fluid" style="padding-top: 10px;">
	<?php
	$app->display_games(false, false);
	?>
	<p><a href="/import/">Add another game</a></p>
	
	<p><a href="/redeem/">Redeem a card</a></p>
</div>
<?php
include('includes/html_stop.php');
?>
