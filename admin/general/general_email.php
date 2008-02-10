<?php
/*
** +---------------------------------------------------+
** | Name :		~/admin/general/general_email.php
** | Begin :	22/06/2006
** | Last :		07/01/2008
** | User :		Genova
** | Project :	Fire-Soft-Board 2 - Copyright FSB group
** | License :	GPL v2.0
** +---------------------------------------------------+
*/

/*
** Permet d'envoyer un Email de masse à un groupe
*/
class Fsb_frame_child extends Fsb_admin_frame
{
	public $errstr = array();
	public $data = array();

	// Maximum de membres par Email
	private $total = 0;
	public $max_user_per_email = 100;

	/*
	** Constructeur
	*/
	public function main()
	{
		$this->data = array(
			'content' =>	trim(Http::request('email_content')),
			'subject' =>	trim(Http::request('email_subject')),
			'users' =>		trim(Http::request('email_users')),
			'groups' =>		(array) Http::request('email_groups'),
			'idx' =>		array(),
		);

		if (Http::request('submit', 'post'))
		{
			$this->check_form();
			if (!$this->errstr)
			{
				$this->send_email();
			}
		}
		$this->form_email();
	}

	/*
	** Formulaire d'envoie de l'Email
	*/
	public function form_email()
	{
		if ($this->errstr)
		{
			Fsb::$tpl->set_switch('error_handler');
		}

		// Liste des groupes
		$list_groups = Html::list_groups('email_groups[]', GROUP_SPECIAL|GROUP_NORMAL, $this->data['groups'], TRUE, array(GROUP_SPECIAL_VISITOR));

		Fsb::$tpl->set_switch('email_mass');
		Fsb::$tpl->set_vars(array(
			'LIST_GROUPS' =>		$list_groups,
			'VALUE_SUBJECT' =>		htmlspecialchars($this->data['subject']),
			'VALUE_USERS' =>		htmlspecialchars($this->data['users']),
			'VALUE_CONTENT' =>		htmlspecialchars($this->data['content']),
			'CONTENT' =>			Html::make_errstr($this->errstr),

			'U_ACTION' =>			sid('index.' . PHPEXT . '?p=general_email'),
		));
	}

	/*
	** Vérifie les données envoyées par le formulaire
	*/
	public function check_form()
	{
		if (empty($this->data['content']))
		{
			$this->errstr[] = Fsb::$session->lang('adm_email_need_content');
		}

		if (empty($this->data['subject']))
		{
			$this->data['subject'] = Fsb::$session->lang('no_subject');
		}

		// On vérifie si les membres envoyés existent
		$sql_nickname = array();
		foreach (explode("\n", $this->data['users']) AS $nickname)
		{
			$nickname = trim($nickname);
			if (!empty($nickname))
			{
				$sql_nickname[$nickname] = $nickname;
			}
		}

		if ($sql_nickname)
		{
			$sql = 'SELECT u_id, u_nickname, u_language, u_email
					FROM ' . SQL_PREFIX . 'users
					WHERE u_nickname IN (\'' . implode('\', \'', $sql_nickname) . '\')
						AND u_id <> 0';
			$result = Fsb::$db->query($sql);
			while ($row = Fsb::$db->row($result))
			{
				if (!isset($this->data['idx'][$row['u_language']]))
				{
					$this->data['idx'][$row['u_language']] = array();
				}

				if ($row['u_email'])
				{
					$this->data['idx'][$row['u_language']][$row['u_id']] = $row['u_email'];
				}
				$this->total++;
				unset($sql_nickname[$row['u_nickname']]);
			}
			Fsb::$db->free($result);

			// Les logins encore dans la liste sont des logins inexistants ...
			foreach ($sql_nickname AS $nickname)
			{
				$this->errstr[] = sprintf(Fsb::$session->lang('adm_email_login_not_exists'), htmlspecialchars($nickname));
			}
		}
	}

	/*
	** Envoie d'Email aux membres / groupes concernés
	*/
	public function send_email()
	{
		@set_time_limit(0);
		
		if (!$this->data['groups'])
		{
			Display::message('adm_email_no_dest');
		}

		// On récupère les membres des groupes
		$send_email = TRUE;
		$result_email = TRUE;
		$this->data['groups'] = array_map('intval', $this->data['groups']);
		$sql = 'SELECT u.u_id, u.u_language, u.u_email
				FROM ' . SQL_PREFIX . 'groups g
				LEFT JOIN ' . SQL_PREFIX . 'groups_users gu
					ON gu.g_id = g.g_id
				INNER JOIN ' . SQL_PREFIX . 'users u
					ON gu.u_id = u.u_id
				WHERE g.g_id IN (' . implode(', ', $this->data['groups']) . ')
					AND g.g_id <> ' . GROUP_SPECIAL_VISITOR . '
				GROUP BY u.u_id, u.u_language, u.u_email';
		$result = Fsb::$db->query($sql);
		while ($row = Fsb::$db->row($result))
		{
			if (!isset($this->data['idx'][$row['u_language']]))
			{
				$this->data['idx'][$row['u_language']] = array();
			}
			$this->data['idx'][$row['u_language']][$row['u_id']] = $row['u_email'];
			$this->total++;

			// On limite le nombre de destinataires (100 par défaut) par Email en BCC
			$send_email = FALSE;
			if ($this->total == $this->max_user_per_email)
			{
				$result_email = TRUE;
				$this->send_email_part($result_email);
				$this->data['idx'] = array();
				$send_email = TRUE;
				$this->total = 0;
			}
		}
		Fsb::$db->free($result);

		if (!$send_email)
		{
			$this->send_email_part($result_email);
		}

		Log::add(Log::EMAIL, 'mass', $this->data['content']);
		Display::message(($result_email) ? 'adm_email_send_well' : 'adm_email_send_bad', 'index.' . PHPEXT . '?p=general_email', 'general_email');
	}

	/*
	** Envoie de l'Email en plusieurs parties
	** -----
	** $result_email ::		Succès d'envoie de l'Email
	*/
	public function send_email_part(&$result_email)
	{
		foreach ($this->data['idx'] AS $mail_lang => $mail_list)
		{
			$mail = new Notify_mail();
			foreach ($mail_list AS $bcc)
			{
				$mail->AddBCC($bcc);
			}

			$mail->Subject = htmlspecialchars($this->data['subject']);
			$mail->set_file(ROOT . 'lang/' . $mail_lang . '/mail/mass.txt');
			$mail->set_vars(array(
				'FORUM_NAME' =>		Fsb::$cfg->get('forum_name'),
				'CONTENT' =>		array($this->data['content']),

				'U_FORUM' =>		Fsb::$cfg->get('fsb_path'),
			));
			$result_email = $mail->Send();
			$mail->SmtpClose();
			unset($mail);
			if (!$result_email)
			{
				return ;
			}
		}
	}
}

/* EOF */