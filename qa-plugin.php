<?php

if(!defined('QA_VERSION'))
{
	header('Location: ../../');
	exit;
}

// language file
qa_register_plugin_phrases('q2a-bonusplus-lang.php', 'q2a_bonusplus_lang');

// page
qa_register_plugin_module('page', 'q2a-bonusplus-page.php', 'q2a_bonusplus_page', 'q2a bonus plus Page');

// layer 
qa_register_plugin_layer('q2a-bonusplus-layer.php', 'q2a bonus plus layer');

// admin
qa_register_plugin_module('module', 'q2a-bonusplus-admin.php', 'q2a_bonusplus_admin', 'q2a bonus plus Admin');


// fns loader (for registering the notification extension)
qa_register_plugin_module('process', 'myplugin-fns-loader.php', 'BonusPlugin_FNSLoader', 'BonusPlugin FNS loader');

	
	function qa_set_bonusplus_to_userpoints($receiverid)
	{
		$sumbonus = qa_db_read_one_value(qa_db_query_sub(
			"SELECT SUM(amount) FROM ^bonusplus WHERE receiverid = #",
			$receiverid
		), true);
		
		if ($sumbonus) {
			qa_db_query_sub(
			"INSERT INTO ^userpoints (userid, bonus) VALUES ($, #) ON DUPLICATE KEY UPDATE bonus=#",
			$receiverid, $sumbonus, $sumbonus
			);
		}

	}
	
	function q2a_bonusplus_reasonname($reasonid)
	{
		return qa_lang('q2a_bonusplus_lang/reason_'.$reasonid);
	}
	
	function q2a_bonusplus_user_sum($userid)
	{
		$sumbonus = qa_db_read_one_value(qa_db_query_sub(
			"SELECT SUM(amount) FROM ^bonusplus WHERE receiverid = #",
			$userid
		), true);
		return $sumbonus;
	}
	
	function q2a_bonusplus_receiver_history($userid)
	{
		$records = qa_db_query_sub(
			"SELECT bonuserid, receiverid, amount, reasonid, bonusnote, bonustime
			FROM ^bonusplus
			WHERE receiverid = #",
			$userid
		);
		return $records;
	}
	
	function q2a_bonusplus_bonuser_history($userid)
	{
		$records = qa_db_query_sub(
			"SELECT bonuserid, receiverid, amount, reasonid, bonusnote, bonustime
			FROM ^bonusplus
			WHERE bonuserid = #",
			$userid
		);
		return $records;
	}
// language string with a fallback, so new texts work even before they are added to the lang file
function q2a_bp_lang($key, $fallback)
{
	$text = qa_lang('q2a_bonusplus_lang/' . $key);
	return (strlen($text) && $text[0] === '[') ? $fallback : $text;
}

// trim, collapse whitespace, limit to 100 characters
function q2a_bp_clean_title($title)
{
	$title = trim(preg_replace('/\s+/u', ' ', (string)$title));
	return function_exists('mb_substr') ? mb_substr($title, 0, 100, 'UTF-8') : substr($title, 0, 100);
}

// all reasons (or only the active ones, which is what the modal shows)
function q2a_bp_get_reasons($activeonly = true)
{
	$sql = 'SELECT reasonid, title, active FROM ^bonusplus_reasons'
		. ($activeonly ? ' WHERE active=1' : '')
		. ' ORDER BY reasonid';
	return qa_db_read_all_assoc(qa_db_query_sub($sql));
}

function q2a_bp_reason_is_active($reasonid)
{
	return (bool)qa_db_read_one_value(
		qa_db_query_sub('SELECT reasonid FROM ^bonusplus_reasons WHERE reasonid=# AND active=1', (int)$reasonid),
		true
	);
}

// returns the id of a reason with this title; creates it only when it does not exist yet (no repeats)
function q2a_bp_find_or_create_reason($title, $active)
{
	$active = ((int)$active === 1) ? 1 : 0;
	$title = q2a_bp_clean_title($title);
	if ($title === '') {
		return null;
	}

	// UNIQUE key on title makes this safe even if two admins add the same text at once
	qa_db_query_sub('INSERT IGNORE INTO ^bonusplus_reasons (title, active, created) VALUES ($, #, NOW())', $title, $active);

	$id = qa_db_read_one_value(
		qa_db_query_sub('SELECT reasonid FROM ^bonusplus_reasons WHERE title=$ LIMIT 1', $title),
		true
	);

	if ($id && $active === 1) {
		// if it was disabled earlier, using it again brings it back
		qa_db_query_sub('UPDATE ^bonusplus_reasons SET active=1 WHERE reasonid=#', (int)$id);
	}

	return $id ? (int)$id : null;
}

// stores one bonus row (note is no longer saved here, only the reason id)
function q2a_save_bonusreasons($bonuserid, $receiverid, $amount, $reasonid, $bonustime)
{
	qa_db_query_sub(
		'INSERT INTO ^bonusplus (bonuserid, receiverid, amount, reasonid, bonustime) VALUES (#, #, #, #, $)',
		(int)$bonuserid, (int)$receiverid, (int)$amount, (int)$reasonid, $bonustime
	);
}


// ---------- history ----------
 
// one page of bonus history for a user, newest first
// $withgiver = true only for people allowed to give bonus points (they may see who gave it)
function q2a_bp_get_history($receiverid, $offset, $limit, $withgiver)
{
	$result = qa_db_read_all_assoc(qa_db_query_sub(
		'SELECT b.bonusid, b.amount, b.bonustime, r.title AS reason, u.handle AS giver
		 FROM ^bonusplus b
		 LEFT JOIN ^bonusplus_reasons r ON r.reasonid=b.reasonid
		 LEFT JOIN ^users u ON u.userid=b.bonuserid
		 WHERE b.receiverid=#
		 ORDER BY b.bonustime DESC, b.bonusid DESC
		 LIMIT #, #',
		(int)$receiverid, (int)$offset, (int)$limit
	));
 
	$rows = array();
	foreach ($result as $r) {
		$row = array(
			'date'   => empty($r['bonustime']) ? null : gmdate('Y-m-d\TH:i:s\Z', strtotime($r['bonustime'] . ' UTC')), // stored as GMT
			'amount' => (int)$r['amount'],
			'reason' => $r['reason'],
		);
		if ($withgiver) {
			$row['giver'] = $r['giver'];
			$row['url']   = strlen((string)$r['giver']) ? qa_path('user/' . $r['giver']) : null;
		}
		$rows[] = $row;
	}
	return $rows;
}
 
// totals for the summary cards
function q2a_bp_get_history_stats($receiverid)
{
	$s = qa_db_read_one_assoc(qa_db_query_sub(
		'SELECT COUNT(*) AS cnt,
		        COALESCE(SUM(amount), 0) AS net,
		        COALESCE(SUM(CASE WHEN amount>0 THEN amount ELSE 0 END), 0) AS added,
		        COALESCE(SUM(CASE WHEN amount<0 THEN amount ELSE 0 END), 0) AS deducted
		 FROM ^bonusplus WHERE receiverid=#',
		(int)$receiverid
	));
 
	return array(
		'count'    => (int)$s['cnt'],
		'net'      => (int)$s['net'],
		'added'    => (int)$s['added'],
		'deducted' => (int)$s['deducted'],
	);
}
 
// how many points came from each reason
function q2a_bp_get_reason_breakdown($receiverid)
{
	$result = qa_db_read_all_assoc(qa_db_query_sub(
		'SELECT r.title AS reason, COUNT(*) AS cnt, SUM(b.amount) AS total
		 FROM ^bonusplus b
		 LEFT JOIN ^bonusplus_reasons r ON r.reasonid=b.reasonid
		 WHERE b.receiverid=#
		 GROUP BY b.reasonid, r.title
		 ORDER BY ABS(SUM(b.amount)) DESC',
		(int)$receiverid
	));
 
	$out = array();
	foreach ($result as $r) {
		$out[] = array('reason' => $r['reason'], 'count' => (int)$r['cnt'], 'total' => (int)$r['total']);
	}
	return $out;
}
 