<?php

class qa_html_theme_layer extends qa_html_theme_base
{
	private $version = 2.0;
	// profile page, user allowed to give bonus points
	private function bonusplus_can_give()
	{
		return qa_is_logged_in()
			&& $this->template == 'user'
			&& qa_get_logged_in_level() >= qa_opt('bonusplus_bonuser_level');
	}
	private function bonusplus_is_admin()
	{
		return qa_is_logged_in() && qa_get_logged_in_level() >= QA_USER_LEVEL_ADMIN;
	}
	private function bonusplus_is_self()
	{
		return qa_is_logged_in() && (int)qa_handle_to_userid(qa_request_part(1)) === (int)qa_get_logged_in_userid();
	}

	// profile page, bonus givers (any profile) or a member looking at their own profile
	private function bonusplus_can_history()
	{
		if (!qa_is_logged_in() || $this->template != 'user') {
			return false;
		}
		if ($this->bonusplus_can_give()) {
			return true;
		}
		//echo "qa_handle_to_userid(qa_request_part(1)) = " . (int)qa_handle_to_userid(qa_request_part(1)) . "<br>";
		return (int)qa_handle_to_userid(qa_request_part(1)) === (int)qa_get_logged_in_userid();
	}

	// scripts and css are needed whenever either modal can appear
	private function bonusplus_active()
	{
		return $this->bonusplus_can_history();
	}

	function head_script()
	{
		qa_html_theme_base::head_script();

		if ($this->bonusplus_active()) {
			$config = array(
				'ajaxUrl'    => qa_path('ajaxbonus'),
				'receiverid' => (int)qa_handle_to_userid(qa_request_part(1)),
				'code'       => qa_get_form_security_code('bonusplus'),
				'min'        => (int)qa_opt('bonusplus_min_amount'),
				'max'        => (int)qa_opt('bonusplus_max_amount'),
				'canGive'    => (bool)$this->bonusplus_can_give(),
				'showGiver'  => (bool)$this->bonusplus_can_give(), // "given by" column only for bonus givers
				'i18n'       => array(
					'amountInvalid'  => q2a_bp_lang('err_amount', 'Enter a whole number greater than 0'),
					'amountRange'    => q2a_bp_lang('err_range', 'Amount must be between %min% and %max%.'),
					'reasonRequired' => q2a_bp_lang('err_reason', 'Please choose a reason.'),
					'otherRequired'  => q2a_bp_lang('err_other', 'Please write the new reason.'),
					'networkError'   => q2a_bp_lang('err_network', 'Could not reach the server. Please try again.'),
					'send'           => q2a_bp_lang('send', 'Send'),
					'sending'        => q2a_bp_lang('sending', 'Sending...'),
					'success'        => q2a_bp_lang('success', 'Bonus sent!'),
					'historyError'   => q2a_bp_lang('history_error', 'Could not load the history. Please try again.'),
					'historyLoading' => q2a_bp_lang('history_loading', 'Loading...'),
					'loadMore'       => q2a_bp_lang('history_more', 'Load more'),
					'giveBonus'      => q2a_bp_lang('give_bonus', 'Give bonus'),
					'historyButton'  => q2a_bp_lang('history_button', 'Bonus history'),
				),
			);

			$this->output(
				'<script>window.BonusPlus = ' . json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>'
			);
			$this->output(
				'<script defer src="' . QA_HTML_THEME_LAYER_URLTOROOT . 'script.js?v=' . $this->version . '"></script>'
			);
		}
	}

	function head_css()
	{
		qa_html_theme_base::head_css();
		// notification icons: needed on every page for logged-in users (the bell is everywhere)
		if (qa_is_logged_in()) {
			$this->output(
				'<link rel="stylesheet" href="' . QA_HTML_THEME_LAYER_URLTOROOT . 'bonusplus-icons.css?v=' . $this->version . '">'
			);
		}

		if ($this->bonusplus_active()) {
			$this->output(
				'<link rel="stylesheet" href="' . QA_HTML_THEME_LAYER_URLTOROOT . 'bonusplus.css?v=' . $this->version . '">'
			);
		}
	}

	// hide the core "bonus" form controls; our modal replaces them
	public function form_button_data($button, $key, $style)
	{
		if (isset($button['tags']) && $button['tags'] == 'name="dosetbonus"') {
			$button['tags'] = 'name="sendbonus" id="hide_button" style="display:none"';
		}

		qa_html_theme_base::form_button_data($button, $key, $style);
	}

	public function form_number($field, $style)
	{
		if (isset($field['tags']) && $field['tags'] == 'name="bonus"') {
			$field['tags'] = 'name="bonus" disabled';
		}
		qa_html_theme_base::form_number($field, $style);
	}

	// "Bonus history" button at the top of the profile content
	public function main_parts($content)
	{
		// members (and givers without the core "bonus" row) get their own Bonus points row
		if (($this->bonusplus_can_history()
			&& isset($content['form_profile']['fields'])
			&& !isset($content['form_profile']['fields']['bonus'])
		)&& !(qa_get_logged_in_level() >= QA_USER_LEVEL_ADMIN)) {
			$newfields = array();
			$placed = false;

			foreach ($content['form_profile']['fields'] as $key => $field) {
				$newfields[$key] = $field;
				if (!$placed && $key === 'points') { // right after the normal Points row
					$newfields['bonusplus'] = $this->bonusplus_points_field();
					$placed = true;
				}
			}
			if (!$placed) {
				$newfields['bonusplus'] = $this->bonusplus_points_field(); // fallback: end of the form
			}
			$content['form_profile']['fields'] = $newfields;
		}

		qa_html_theme_base::main_parts($content);
	}

	private function bonusplus_points_field()
	{
		$receiverid = (int)qa_handle_to_userid(qa_request_part(1));
		$stats = q2a_bp_get_history_stats($receiverid);

		$html = '<div class="bp-inline bp-inline-row">'
			. '<span class="bp-points-value">' . (int)$stats['net'] . '</span>'
			. (($this->bonusplus_can_give() && ($this->bonusplus_is_admin() || !$this->bonusplus_is_self()))
				? '<button type="button" id="bp-give-open" class="bp-inline-btn bp-inline-primary">'
					. qa_html(q2a_bp_lang('give_bonus', 'Give bonus')) . '</button>'
				: '')
			. '<button type="button" id="bp-history-open" class="bp-inline-btn">'
			. qa_html(q2a_bp_lang('history_button', 'Bonus history')) . '</button>'
			. '</div>';

		return array(
			'type'  => 'custom',
			'label' => qa_lang_html('q2a_bonusplus_lang/bonus_points'),
			'html'  => $html,
		);
	}

	private function bonusplus_history_modal()
	{
		$handle = qa_html(qa_request_part(1));
		$giver  = $this->bonusplus_can_give();

		$this->output('
<div class="bp-modal" id="bonusplus-history" hidden>
	<div class="bp-backdrop" data-bp-close></div>
	<div class="bp-dialog bp-dialog-wide" role="dialog" aria-modal="true" aria-labelledby="bph-title">
		<button type="button" class="bp-close" data-bp-close aria-label="Close">&times;</button>
		<h3 class="bp-title" id="bph-title">' . qa_html(q2a_bp_lang('history_title', 'Bonus history')) . '</h3>
		<p class="bp-sub">@' . $handle . '</p>

		<div class="bp-alert" id="bph-alert" role="alert" hidden></div>

		<div class="bp-stats">
			<div class="bp-stat"><span>' . qa_html(q2a_bp_lang('history_net', 'Net total')) . '</span><strong id="bph-net">-</strong></div>
			<div class="bp-stat"><span>' . qa_html(q2a_bp_lang('history_added', 'Added')) . '</span><strong id="bph-added" class="bp-pos">-</strong></div>
			<div class="bp-stat"><span>' . qa_html(q2a_bp_lang('history_deducted', 'Deducted')) . '</span><strong id="bph-deducted" class="bp-neg">-</strong></div>
			<div class="bp-stat"><span>' . qa_html(q2a_bp_lang('history_entries', 'Entries')) . '</span><strong id="bph-count">-</strong></div>
		</div>

		<div class="bp-table-wrap" id="bph-table-wrap">
			<table class="bp-table">
				<thead><tr>
					<th>' . qa_html(q2a_bp_lang('history_date', 'Date')) . '</th>
					<th class="bp-num">' . qa_html(q2a_bp_lang('history_points', 'Points')) . '</th>
					<th>' . qa_html(q2a_bp_lang('reason_label', 'Reason')) . '</th>
					' . ($giver ? '<th>' . qa_html(q2a_bp_lang('history_by', 'Given by')) . '</th>' : '') . '
				</tr></thead>
				<tbody id="bph-body"></tbody>
			</table>
		</div>
		<p class="bp-empty" id="bph-empty" hidden>' . qa_html(q2a_bp_lang('history_empty', 'No bonus points have been given yet.')) . '</p>

		<details class="bp-breakdown" id="bph-breakdown" hidden>
			<summary>' . qa_html(q2a_bp_lang('history_by_reason', 'Totals by reason')) . '</summary>
			<table class="bp-table">
				<thead><tr>
					<th>' . qa_html(q2a_bp_lang('reason_label', 'Reason')) . '</th>
					<th class="bp-num">' . qa_html(q2a_bp_lang('history_entries', 'Entries')) . '</th>
					<th class="bp-num">' . qa_html(q2a_bp_lang('history_points', 'Points')) . '</th>
				</tr></thead>
				<tbody id="bph-reasons"></tbody>
			</table>
		</details>

		<div class="bp-actions">
			<button type="button" class="bp-btn bp-btn-ghost" id="bph-more" hidden>' . qa_html(q2a_bp_lang('history_more', 'Load more')) . '</button>
			<button type="button" class="bp-btn bp-btn-primary" data-bp-close>' . qa_html(q2a_bp_lang('close', 'Close')) . '</button>
		</div>
	</div>
</div>
			');
	}

	public function body_hidden()
	{
		if ($this->bonusplus_can_history()) {
			$this->bonusplus_history_modal();
		}

		if ($this->bonusplus_can_give()) {
			$handle = qa_html(qa_request_part(1));
			$min    = (int)qa_opt('bonusplus_min_amount');
			$max    = (int)qa_opt('bonusplus_max_amount');

			$options = '';
			foreach (q2a_bp_get_reasons(true) as $r) {
				$options .= '<option value="' . (int)$r['reasonid'] . '" data-effect="' . ((int)$r['effect'] < 0 ? -1 : 1) . '">' . qa_html($r['title']) . '</option>';
			}

			$this->output('
<div class="bp-modal" id="bonusplus-popup" hidden>
	<div class="bp-backdrop" data-bp-close></div>
	<div class="bp-dialog" role="dialog" aria-modal="true" aria-labelledby="bp-title">
		<button type="button" class="bp-close" data-bp-close aria-label="Close">&times;</button>
		<h3 class="bp-title" id="bp-title">' . qa_html(q2a_bp_lang('reason', 'Give bonus points')) . '</h3>
		<p class="bp-sub">@' . $handle . '</p>

		<div class="bp-alert" id="bp-alert" role="alert" hidden></div>

		<div class="bp-field">
			<label for="bp-amount">' . qa_html(q2a_bp_lang('bonus_amount', 'Amount')) . '</label>
			<input type="number" id="bp-amount" step="1" min="1" max="' . $max . '" inputmode="numeric" placeholder="e.g. 10" value="100">
			<small id="bp-range">1 &hellip; ' . $max . '</small>
		</div>

		<div class="bp-field">
			<label for="bp-reason">' . qa_html(q2a_bp_lang('reason_label', 'Reason')) . '</label>
			<select id="bp-reason">
				' . $options . '
				<option value="other" data-effect="1">' . qa_html(q2a_bp_lang('reason_other', 'Other...')) . '</option>
			</select>
		</div>

		<div class="bp-field" id="bp-other-wrap" hidden>
			<label for="bp-other">' . qa_html(q2a_bp_lang('reason_new', 'New reason')) . '</label>
			<textarea id="bp-other" rows="2" maxlength="100" placeholder="' . qa_html(q2a_bp_lang('reason_new_ph', 'Short description')) . '"></textarea>
			<small>' . qa_html(q2a_bp_lang('reason_new_note', 'It will be saved and offered as a reason next time.')) . '</small>
		</div>
		<div class="bp-field">
			<label>' . qa_html(q2a_bp_lang('direction_label', 'Type')) . '</label>
			<div class="bp-seg" role="radiogroup">
				<label class="bp-seg-opt bp-seg-add"><input type="radio" name="bp-dir" value="add" checked><span>' . qa_html(q2a_bp_lang('dir_add', 'Add (+)')) . '</span></label>
				<label class="bp-seg-opt bp-seg-deduct"><input type="radio" name="bp-dir" value="deduct"><span>' . qa_html(q2a_bp_lang('dir_deduct', 'Deduct (-)')) . '</span></label>
			</div>
			<small id="bp-preview"></small>
		</div>

		<div class="bp-actions">
			<button type="button" class="bp-btn bp-btn-ghost" data-bp-close>' . qa_html(q2a_bp_lang('cancel', 'Cancel')) . '</button>
			<button type="button" class="bp-btn bp-btn-primary" id="bp-submit">' . qa_html(q2a_bp_lang('send', 'Send')) . '</button>
		</div>
	</div>
</div>
			');
		}

		qa_html_theme_base::body_hidden();
	}

} // end qa_html_theme_layer