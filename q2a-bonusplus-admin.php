<?php

class q2a_bonusplus_admin
{
	// engine / charset / collation every plugin table should end up with
	private $collation = 'utf8mb4_unicode_ci';

	// does the column exist in this (real, prefixed) table?
	private function column_exists($table, $column)
	{
		return (bool)qa_db_read_one_value(
			qa_db_query_sub('SHOW COLUMNS FROM ' . $table . ' LIKE $', $column),
			true
		);
	}

	// engine + collation of a table, or null if the table does not exist
	private function table_info($table)
	{
		return qa_db_read_one_assoc(
			qa_db_query_sub(
				'SELECT ENGINE AS engine, TABLE_COLLATION AS collation_name
				 FROM information_schema.TABLES
				 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=$',
				$table
			),
			true
		);
	}

	// queries that bring an existing table to InnoDB + utf8mb4 (nothing if it already is)
	private function upgrade_table($name)
	{
		$queries = array();
		$info = $this->table_info(qa_db_add_table_prefix($name));

		if ($info) {
			if (strcasecmp($info['engine'], 'InnoDB') !== 0) {
				$queries[] = 'ALTER TABLE ^' . $name . ' ENGINE=InnoDB';
			}
			if (strcasecmp($info['collation_name'], $this->collation) !== 0) {
				$queries[] = 'ALTER TABLE ^' . $name . ' CONVERT TO CHARACTER SET utf8mb4 COLLATE ' . $this->collation;
			}
		}
		return $queries;
	}

	// create / migrate the tables. Safe to run on a fresh site, on the first version of the plugin,
	// or on a site that is already up to date (then it returns nothing).
	public function init_queries($tableslc)
	{
		$queries = array();

		$bonustable  = qa_db_add_table_prefix('bonusplus');
		$reasontable = qa_db_add_table_prefix('bonusplus_reasons');
		$tail = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=' . $this->collation;

		// ---- ^bonusplus ----
		if (!in_array(strtolower($bonustable), $tableslc)) {
			require_once QA_INCLUDE_DIR . 'qa-app-users.php';

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
				) ' . $tail . ';
			';
		}
		else {
			$queries = array_merge($queries, $this->upgrade_table('bonusplus'));
		}

		// ---- ^bonusplus_reasons ----
		// effect: 1 = adds points, -1 = deducts points, 0 = not decided yet (treated as 1)
		if (!in_array(strtolower($reasontable), $tableslc)) {
			$queries[] = '
				CREATE TABLE `^bonusplus_reasons` (
				  `reasonid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
				  `title` varchar(100) NOT NULL,
				  `active` tinyint(1) NOT NULL DEFAULT 1,
				  `effect` tinyint(4) NOT NULL DEFAULT 0,
				  `created` datetime NULL,
				  PRIMARY KEY (`reasonid`),
				  UNIQUE KEY `title` (`title`)
				) ' . $tail . ';
			';

			// default reasons keep ids 1-8, so rows already stored in ^bonusplus still match.
			// Change a 1 to -1 below for a default reason that should deduct points.
			$defaulteffects = array(1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1, 7 => 1, 8 => 1);

			$values = array();
			foreach ($defaulteffects as $i => $effect) {
				$title = q2a_bp_clean_title(q2a_bp_lang('reason_' . $i, 'Reason ' . $i));
				$values[] = '(' . $i . ", '" . qa_db_escape_string($title) . "', 1, " . (int)$effect . ', NOW())';
			}
			$queries[] = 'INSERT IGNORE INTO ^bonusplus_reasons (reasonid, title, active, effect, created) VALUES ' . implode(',', $values);
		}
		else {
			$queries = array_merge($queries, $this->upgrade_table('bonusplus_reasons'));

			$hascolumn = $this->column_exists($reasontable, 'effect');

			if (!$hascolumn) {
				$queries[] = 'ALTER TABLE ^bonusplus_reasons ADD COLUMN `effect` tinyint(4) NOT NULL DEFAULT 1 AFTER `active`';
			}
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

			// rename / enable / disable / change direction of existing reasons
			// (disabled instead of deleted, so old bonus history still shows its reason)
			foreach (q2a_bp_get_reasons(false) as $r) {
				$id     = (int)$r['reasonid'];
				$title  = q2a_bp_clean_title(qa_post_text('bp_title_' . $id));
				$active = qa_post_text('bp_active_' . $id) ? 1 : 0;
				$effect = (qa_post_text('bp_effect_' . $id) === '-1') ? -1 : 1;

				if ($title === '') {
					$title = $r['title']; // never save an empty name
				}
				qa_db_query_sub(
					'UPDATE IGNORE ^bonusplus_reasons SET title=$, active=#, effect=# WHERE reasonid=#',
					$title, $active, $effect, $id
				);
			}

			// add a new reason (if the same text already exists, that reason is updated instead)
			$newreason = q2a_bp_clean_title(qa_post_text('bp_new_reason'));
			$neweffect = (qa_post_text('bp_new_effect') === '-1') ? -1 : 1;
			if ($newreason !== '') {
				$newid = q2a_bp_find_or_create_reason($newreason, true, $neweffect);
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
			$id  = (int)$r['reasonid'];
			$eff = ((int)$r['effect'] < 0) ? -1 : 1; // 0 (not decided) counts as "add"

			$rows .= '<tr>'
				. '<td style="padding:4px 8px;">#' . $id . '</td>'
				. '<td style="padding:4px 8px;"><input type="text" class="qa-form-tall-text" maxlength="100" name="bp_title_' . $id . '" value="' . qa_html($r['title']) . '"></td>'
				. '<td style="padding:4px 8px;"><select name="bp_effect_' . $id . '">'
				. '<option value="1"' . ($eff > 0 ? ' selected' : '') . '>Add (+)</option>'
				. '<option value="-1"' . ($eff < 0 ? ' selected' : '') . '>Deduct (&minus;)</option>'
				. '</select></td>'
				. '<td style="padding:4px 8px;text-align:center;"><input type="checkbox" name="bp_active_' . $id . '" value="1"' . ($r['active'] ? ' checked' : '') . '></td>'
				. '</tr>';
		}

		$fields[] = array(
			'type'  => 'custom',
			'label' => 'Bonus reasons',
			'html'  => '<table style="border-collapse:collapse;max-width:100%;"><thead><tr>'
				. '<th style="text-align:left;padding:4px 8px;">ID</th>'
				. '<th style="text-align:left;padding:4px 8px;">Reason</th>'
				. '<th style="text-align:left;padding:4px 8px;">Default</th>'
				. '<th style="padding:4px 8px;">Active</th>'
				. '</tr></thead><tbody>' . $rows . '</tbody></table>',
			'note'  => 'Default decides whether the typed amount is added or deducted. It can still be switched while giving a bonus. Untick "Active" to remove a reason from the list; it is not deleted, so old bonus history keeps its text.',
		);

		$fields[] = array(
			'type'  => 'text',
			'label' => 'Add a new reason',
			'tags'  => 'name="bp_new_reason" maxlength="100"',
			'value' => '',
			'note'  => 'Leave empty if you do not want to add one. Reasons typed by users under "Other" are added here automatically.',
		);

		$fields[] = array(
			'type'    => 'select',
			'label'   => 'Default for the new reason',
			'tags'    => 'name="bp_new_effect"',
			'options' => array('1' => 'Add (+)', '-1' => 'Deduct (-)'),
			'value'   => 'Add (+)',
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