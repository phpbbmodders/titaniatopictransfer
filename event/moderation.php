<?php

namespace phpbbmodders\titaniatopictransfer\event;

use phpbb\auth\auth;
use phpbb\extension\manager;
use phpbb\language\language;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class moderation implements EventSubscriberInterface
{
	public function __construct(private auth $auth, private language $language, private manager $extensions,
		private \phpbb\request\request_interface $request,
		private \phpbb\controller\helper $helper)
	{
	}

	public static function getSubscribedEvents(): array
	{
		return ['core.viewtopic_add_quickmod_option_before' => 'add_quickmod',
			'core.modify_quickmod_options' => 'dispatch_quickmod'];
	}

	public function add_quickmod(\phpbb\event\data $event): void
	{
		if (!$this->extensions->is_enabled('phpbb/titania')
			|| !$this->auth->acl_get('m_move', (int) $event['forum_id'])
			|| !$this->auth->acl_get('u_titania_mod_post_mod')
			|| (int) $event['topic_data']['topic_status'] === ITEM_MOVED)
		{
			return;
		}

		$this->language->add_lang('transfer', 'phpbbmodders/titaniatopictransfer');
		$options = $event['quickmod_array'];
		$options['titania_transfer'] = ['TITANIA_TRANSFER', true];
		$event['quickmod_array'] = $options;
	}

	public function dispatch_quickmod(\phpbb\event\data $event): void
	{
		if ($event['action'] === 'titania_transfer')
		{
			redirect($this->helper->route('phpbbmodders_titania_transfer', [
				'topic_id' => $this->request->variable('t', 0),
			], false));
		}
	}
}
