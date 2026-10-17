<?php

class q2a_bonusplus_admin
{
	// create the tables if they do not exist yet
	public function init_queries($tableslc)
	{
		$queries = array();

		$bonustable  = qa_db_add_table_prefix('bonusplus');
		$reasontable = qa_db_add_table_prefix('bonusplus_reasons');

		if (!in_array($bonustable, $tableslc)) {
			require_once QA_INCLUDE_DIR . 'qa-app-users.php';

			// no bonusnote column any more: the text lives in ^bonusplus_reasons
			$queries[] = '
				CREATE TABLE `^bonusplus` (
				  `bonusid` int(10) NOT NULL AUTO_INCREMENT,
				  `bonuserid` int(10) UNSIGNED NOT NULL,
				  `receiverid` int(10) UNSIGNED NOT NULL,
				  `amount` int(10) NOT NULL,
				  `reasonid` int(10) UNSIGNED NOT NULL,
				  `bonustime` datetime NULL,
				  PRIMARY KEY (`bonusid`),
				  KEY `receiverid` (`receiverid`),
				  KEY `reasonid` (`reasonid`)
				)
				ENGINE=MyISAM DEFAULT CHARSET=utf8;
			';
		}

		if (!in_array($reasontable, $tableslc)) {
			$queries[] = '
				CREATE TABLE `^bonusplus_reasons` (
				  `reasonid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
				  `title` varchar(100) NOT NULL,
				  `active` tinyint(1) NOT NULL DEFAULT 1,
				  `created` datetime NULL,
				  PRIMARY KEY (`reasonid`),
				  UNIQUE KEY `title` (`title`)
				)
				ENGINE=MyISAM DEFAULT CHARSET=utf8;
			';

			// default reasons keep ids 1-8, so rows already stored in ^bonusplus still match
			$values = array();
			for ($i = 1; $i <= 8; $i++) {
				$title = q2a_bp_clean_title(q2a_bp_lang('reason_' . $i, 'Reason ' . $i));
				$values[] = '(' . $i . ", '" . qa_db_escape_string($title) . "', 1, NOW())";
			}
			$queries[] = 'INSERT IGNORE INTO ^bonusplus_reasons (reasonid, title, active, created) VALUES ' . implode(',', $values);
		}

		return empty($queries) ? null : $queries;
	}

	// option's value is requested
	public function option_default($option)
	{
		switch ($option) {
			case 'bonusplus_min_amount':
				return -1000;
			case 'bonusplus_max_amount':
				return 1000;
			case 'bonusplus_bonuser_level':
				return QA_USER_LEVEL_ADMIN;
			default:
				return null;
		}
	}

	public function allow_template($template)
	{
		return ($template != 'admin');
	}

	private $userlevels = [
		'expert' => QA_USER_LEVEL_EXPERT,
		'editor' => QA_USER_LEVEL_EDITOR,
		'mod'    => QA_USER_LEVEL_MODERATOR,
		'admin'  => QA_USER_LEVEL_ADMIN,
	];

	private $userlevels_text = [
		'expert' => 'Expert',
		'editor' => 'Editor',
		'mod'    => 'Moderator',
		'admin'  => 'Administrator',
	];

	public function admin_form(&$qa_content)
	{
		$saved = qa_clicked('bonusplus_save');

		if ($saved) {

			$bonuslevel = qa_post_text('bonusplus_bonuser_level_field');
			if (!in_array($bonuslevel, array_keys($this->userlevels))) {
				$bonuslevel = 'admin';
			}
			qa_opt('bonusplus_bonuser_level', $this->userlevels[$bonuslevel]);

			qa_opt('bonusplus_min_amount', (int)qa_post_text('bonusplus_min_amount_field'));
			qa_opt('bonusplus_max_amount', (int)qa_post_text('bonusplus_max_amount_field'));

			// rename / enable / disable existing reasons
			// (disabled instead of deleted, so old bonus history still shows its reason)
			foreach (q2a_bp_get_reasons(false) as $r) {
				$id    = (int)$r['reasonid'];
				$title = q2a_bp_clean_title(qa_post_text('bp_title_' . $id));
				$active = qa_post_text('bp_active_' . $id) ? 1 : 0;

				if ($title === '') {
					$title = $r['title']; // never save an empty name
				}
				qa_db_query_sub(
					'UPDATE IGNORE ^bonusplus_reasons SET title=$, active=# WHERE reasonid=#',
					$title, $active, $id
				);
			}

			// add a new reason (ignored if the same text already exists)
			$newreason = q2a_bp_clean_title(qa_post_text('bp_new_reason'));
			if ($newreason !== '') {
				q2a_bp_find_or_create_reason($newreason, true);
			}
		}

		$bl_id    = qa_opt('bonusplus_bonuser_level');
		$bl_alias = array_search($bl_id, $this->userlevels);
		$bl_value = $this->userlevels_text[$bl_alias];

		$fields = array();

		$fields[] = array(
			'type'    => 'select',
			'label'   => qa_lang('q2a_bonusplus_lang/bonuser_level'),
			'tags'    => 'name="bonusplus_bonuser_level_field" id="bonusplus_bonuser_level_field"',
			'options' => $this->userlevels_text,
			'value'   => $bl_value,
			'note'    => 'Which user level is able to give bonus points.',
		);

		$fields[] = array(
			'type'   => 'number',
			'label'  => qa_lang('q2a_bonusplus_lang/min_bonus_amount_label'),
			'tags'   => 'name="bonusplus_min_amount_field"',
			'value'  => qa_opt('bonusplus_min_amount'),
			'suffix' => 'points',
			'note'   => qa_lang('q2a_bonusplus_lang/min_bonus_amount_note'),
		);

		$fields[] = array(
			'type'   => 'number',
			'label'  => qa_lang('q2a_bonusplus_lang/max_bonus_amount_label'),
			'tags'   => 'name="bonusplus_max_amount_field"',
			'value'  => qa_opt('bonusplus_max_amount'),
			'suffix' => 'points',
			'note'   => qa_lang('q2a_bonusplus_lang/max_bonus_amount_note'),
		);

		// ---- reasons manager ----
		$rows = '';
		foreach (q2a_bp_get_reasons(false) as $r) {
			$id = (int)$r['reasonid'];
			$rows .= '<tr>'
				. '<td style="padding:4px 8px;">#' . $id . '</td>'
				. '<td style="padding:4px 8px;"><input type="text" class="qa-form-tall-text" maxlength="100" name="bp_title_' . $id . '" value="' . qa_html($r['title']) . '"></td>'
				. '<td style="padding:4px 8px;text-align:center;"><input type="checkbox" name="bp_active_' . $id . '" value="1"' . ($r['active'] ? ' checked' : '') . '></td>'
				. '</tr>';
		}

		$fields[] = array(
			'type'  => 'custom',
			'label' => 'Bonus reasons',
			'html'  => '<table style="border-collapse:collapse;max-width:100%;"><thead><tr>'
				. '<th style="text-align:left;padding:4px 8px;">ID</th>'
				. '<th style="text-align:left;padding:4px 8px;">Reason</th>'
				. '<th style="padding:4px 8px;">Active</th>'
				. '</tr></thead><tbody>' . $rows . '</tbody></table>',
			'note'  => 'Untick "Active" to remove a reason from the list. It is not deleted, so old bonus history keeps its text.',
		);

		$fields[] = array(
			'type'  => 'text',
			'label' => 'Add a new reason',
			'tags'  => 'name="bp_new_reason" maxlength="100"',
			'value' => '',
			'note'  => 'Leave empty if you do not want to add one. Reasons typed by users under "Other" are added here automatically.',
		);

		return array(
			'ok'      => $saved ? 'Settings saved' : null,
			'fields'  => $fields,
			'buttons' => array(
				array(
					'label' => qa_lang_html('main/save_button'),
					'tags'  => 'name="bonusplus_save"',
				),
			),
		);
	}

} // END q2a_bonusplus_admin