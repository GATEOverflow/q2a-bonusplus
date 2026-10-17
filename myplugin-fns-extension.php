<?php
/*
	Plugin Name: q2a Bonus Plus
	Notification integration (same pattern as the post-deleted plugin).
	Load this file only when the notification plugin is active, because the class implements
	its interface - use the same condition/registration you use for MyPlugin_FNSExtension.
*/

class BonusPlus_FNSExtension implements PUPI_FNS_IExtension
{
	const PLUGIN_ID = 'Bonus_PLUS';

	const NOTIFICATION_ID_Received = 0; // points were added
	const NOTIFICATION_ID_Deducted = 1; // points were taken away

	public function processNotification(array $notification, $userId, string $handle): array
	{
		if ($notification['plugin_id'] !== self::PLUGIN_ID) {
			return $notification;
		}

		$params = $notification['params'];
		$amount = isset($params['bonus.amount']) ? (int)$params['bonus.amount'] : 0;
		$reason = isset($params['bonus.reason']) ? (string)$params['bonus.reason'] : '';

		// link to the member's own profile, where the bonus history button is
		$profilehandle = !empty($params['receiver.handle']) ? $params['receiver.handle'] : $handle;

		$result = [
			'plugin_id'  => $notification['plugin_id'],
			'event_name' => '',
			'event_id'   => $notification['event_id'],
			'name'       => '',
			'created_at' => $notification['created_at'],
			'url'        => qa_path('user/' . $profilehandle, null, qa_opt('site_url')),
			'text'       => $reason, // the reason is shown under the title
		];

		switch ($notification['event_id']) {
			case self::NOTIFICATION_ID_Received:
				$result['event_name'] = 'Bonus_Received';
				$result['name'] = strtr(
					q2a_bp_lang('notify_received', 'You received %amount% bonus points'),
					array('%amount%' => abs($amount))
				);
				$result['icon'] = 'bp-fns-icon-plus';
				break;

			case self::NOTIFICATION_ID_Deducted:
				$result['event_name'] = 'Bonus_Deducted';
				$result['name'] = strtr(
					q2a_bp_lang('notify_deducted', '%amount% bonus points were deducted'),
					array('%amount%' => abs($amount))
				);
				$result['icon'] = 'bp-fns-icon-minus';
				break;

			default:
		}

		return $result;
	}
}