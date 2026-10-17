<?php

class q2a_bonusplus_page
{
	var $directory;
	var $urltoroot;

	function load_module($directory, $urltoroot)
	{
		$this->directory = $directory;
		$this->urltoroot = $urltoroot;
	}

	// for display in admin interface under admin/pages
	function suggest_requests()
	{
		return array(
			array(
				'title'   => 'Ajax Bonus',
				'request' => 'ajaxbonus',
				'nav'     => 'M',
			),
		);
	}

	function match_request($request)
	{
		return ($request == 'ajaxbonus');
	}

	private function respond($data)
	{
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode($data);
		exit();
	}

	// bonus history: bonus givers can see anyone's, a member can see only their own
	private function history($data, $isgiver)
	{
		$receiverid = isset($data['receiverid']) ? (int)$data['receiverid'] : 0;

		if ($receiverid <= 0 || (!$isgiver && $receiverid !== (int)qa_get_logged_in_userid())) {
			$this->respond(array('error' => q2a_bp_lang('err_forbidden', 'You are not allowed to see this.')));
		}

		$limit  = 20;
		$offset = isset($data['offset']) ? max(0, (int)$data['offset']) : 0;

		$reply = array(
			'success' => '1',
			'rows'    => q2a_bp_get_history($receiverid, $offset, $limit, $isgiver),
		);

		// summary only needed on the first page
		if ($offset === 0) {
			$reply['stats']   = q2a_bp_get_history_stats($receiverid);
			$reply['reasons'] = q2a_bp_get_reason_breakdown($receiverid);
		}

		$this->respond($reply);
	}

	function process_request($request)
	{
		if (!qa_is_logged_in()) {
			$this->respond(array('error' => q2a_bp_lang('err_forbidden', 'You are not allowed to give bonus points.')));
		}

		$isgiver = qa_get_logged_in_level() >= (int)qa_opt('bonusplus_bonuser_level');

		$postdata = qa_post_text('ajaxdata');
		$data = empty($postdata) ? null : json_decode($postdata, true);

		if (!is_array($data)) {
			$this->respond(array('error' => q2a_bp_lang('err_nodata', 'No data received.')));
		}

		// security code (generated in the layer)
		if (!qa_check_form_security_code('bonusplus', isset($data['code']) ? $data['code'] : '')) {
			$this->respond(array('error' => q2a_bp_lang('err_security', 'Security check failed. Please reload the page.')));
		}

		// read-only history request
		if (isset($data['action']) && $data['action'] === 'history') {
			$this->history($data, $isgiver);
		}

		// everything below gives bonus points, so the configured level is required
		if (!$isgiver) {
			$this->respond(array('error' => q2a_bp_lang('err_forbidden', 'You are not allowed to give bonus points.')));
		}

		// the giver is always the logged-in user, never taken from the browser
		$bonuserid  = (int)qa_get_logged_in_userid();
		$receiverid = isset($data['receiverid']) ? (int)$data['receiverid'] : 0;

		$userexists = $receiverid > 0 && qa_db_read_one_value(
			qa_db_query_sub('SELECT userid FROM ^users WHERE userid=#', $receiverid),
			true
		);
		if (!$userexists) {
			$this->respond(array('error' => q2a_bp_lang('err_receiver', 'User not found.')));
		}

		//if user is not admin, he can't give bonus points to himself
		if ($bonuserid === $receiverid && (qa_get_logged_in_level() < QA_USER_LEVEL_ADMIN)) {
			$this->respond(array('error' => q2a_bp_lang('err_self', 'You cannot give bonus points to yourself.')));
		}

		// amount: whole number, not 0, inside the admin's min/max
		$amount = isset($data['amount']) ? filter_var($data['amount'], FILTER_VALIDATE_INT) : false;
		$min = (int)qa_opt('bonusplus_min_amount');
		$max = (int)qa_opt('bonusplus_max_amount');

		if ($amount === false || $amount === 0) {
			$this->respond(array('error' => q2a_bp_lang('err_amount', 'Enter a whole number other than 0.')));
		}
		if ($amount < $min || $amount > $max) {
			$this->respond(array('error' => strtr(
				q2a_bp_lang('err_range', 'Amount must be between %min% and %max%.'),
				array('%min%' => $min, '%max%' => $max)
			)));
		}

		// reason: an existing active one, or "other" = new text saved once in the reasons table
		$reason = isset($data['reasonid']) ? $data['reasonid'] : '';

		if ($reason === 'other') {
			$reasonid = q2a_bp_find_or_create_reason(isset($data['newreason']) ? $data['newreason'] : '');
			if (!$reasonid) {
				$this->respond(array('error' => q2a_bp_lang('err_other', 'Please write the new reason.')));
			}
		}
		else {
			$reasonid = (int)$reason;
			if ($reasonid <= 0 || !q2a_bp_reason_is_active($reasonid)) {
				$this->respond(array('error' => q2a_bp_lang('err_reason', 'Please choose a reason.')));
			}
		}

		$bonustime = gmdate('Y-m-d H:i:s', time());

		q2a_save_bonusreasons($bonuserid, $receiverid, $amount, $reasonid, $bonustime);

		require_once QA_INCLUDE_DIR . 'db/points.php';

		qa_set_bonusplus_to_userpoints($receiverid);
		qa_db_points_update_ifuser($receiverid, null);
		
		// notify the receiver (skipped when the notification plugin is not there, or for self-bonus)
		if (class_exists('PUPI_FNS_NotificationsService') && class_exists('BonusPlus_FNSExtension') && $receiverid !== $bonuserid) {
			$reasontitle = qa_db_read_one_value(
				qa_db_query_sub('SELECT title FROM ^bonusplus_reasons WHERE reasonid=#', $reasonid),
				true
			);

			(new PUPI_FNS_NotificationsService())->addNotification(
				$amount > 0
					? BonusPlus_FNSExtension::NOTIFICATION_ID_Received
					: BonusPlus_FNSExtension::NOTIFICATION_ID_Deducted,
				BonusPlus_FNSExtension::PLUGIN_ID,
				[$receiverid],
				[
					'bonus.amount'    => $amount,
					'bonus.reason'    => (string)$reasontitle,
					'receiver.handle' => qa_userid_to_handle($receiverid),
				],
				null
			);
		}

		$this->respond(array('success' => '1', 'reasonid' => (int)$reasonid));
	}

}; // END q2a_bonusplus_page