<?php
/*
** +---------------------------------------------------+
** | Name :		~/main/class/class_module.php
** | Begin :	29/05/2005
** | Last :		05/11/2007
** | User :		Genova
** | Project :	Fire-Soft-Board 2 - Copyright FSB group
** | License :	GPL v2.0
** +---------------------------------------------------+
*/

class Module extends Fsb_model
{
	// Objet Xml
	public $xml;

	// Ressource sur les données actuelles de l'instruction d'installation
	private $handler = NULL;

	// Configuration de la classe
	private $config = array();

	// Log d'erreur
	public $log_error = array();

	// Contenu du fichier
	private $file_content = '';

	// Code à trouver
	private $find_code = '';

	// Fichier actuellement ouvert
	private $file_open = NULL;

	// Gestion de la duplication
	private $duplicat;

	// Objet File
	public $file;

	// Fichier n'existe pas
	const MOD_ERROR_FILE_NOT_FOUND = 1;

	// Code non trouvé dans le fichier
	const MOD_ERROR_CODE_NOT_FOUND = 2;

	// Fichier non accessible en écriture
	const MOD_ERROR_PERMISSION_DENIED = 3;

	// Erreur SQL
	const MOD_ERROR_SQL = 4;

	// Répertoire non accessible en écriture
	const MOD_ERROR_DIR_NOT_WRITABLE = 5;

	// Instruction inconnue
	const MOD_ERROR_UNKNOWN_INSTRUCTION = 6;

	/*
	** Constructeur
	*/
	public function __construct()
	{
		$this->set_config(array(
			'install_sql' => 		TRUE,
			'install_duplicat' =>	TRUE,
			'install_file' =>		TRUE,
			'install' =>			FALSE,
			'mod_path' =>			ROOT,
		));
	}

	/*
	** Assigne une configuration à la classe.
	** -----
	** $data ::		Si $data est un tableau, on l'ajoute entièrement a la configuration.
	**					Sinon, on se sert de $data comme clef et de $value comme valeur.
	** $value ::	Valeur de $data si celui ci n'est pas un tableau.
	*/
	public function set_config($data, $value = '')
	{
		if (is_array($data))
		{
			foreach ($data AS $k => $v)
			{
				$this->config[$k] = $v;
			}
		}
		else
		{
			$this->config[$data] = $value;
		}
	}

	/*
	** Retourne la valeur d'un élément de configuration
	** -----
	** $key ::	Clef de configuration
	*/
	public function get_config($key)
	{
		return ((isset($this->config[$key])) ? $this->config[$key] : NULL);
	}

	/*
	** Créé l'objet $file pour manipuler les fichiers
	** -----
	** $use_ftp ::		Définit si on utilise une connexion FTP
	*/
	public function file_system($use_ftp)
	{
		// Instance de la classe File
		$this->file = File::factory($use_ftp);
	}

	/*
	** Charge un fichier template
	** -----
	** $template_name ::		Chemin vers le fichier tempkate à charger. Il peut s'agir d'un code XML
	** $load_type ::			'file' si $template_name est un fichier, 'code' si c'est du code XML
	*/
	public function load_template($template_name, $load_type = 'file')
	{
		$this->xml = new Xml();
		switch ($load_type)
		{
			case 'file' :
				$method = 'load_file';
			break;

			case 'code' :
				$method = 'load_content';
			break;
		}
		$this->xml->$method($template_name);

		// Gestion de la duplication pour la commande "ouvrir"
		if ($this->get_config('install_duplicat'))
		{
			$duplicat = FALSE;
			$add_node = array();
			foreach ($this->xml->document->instruction[0]->line AS $handler)
			{
				$method = $this->convert_to_valid_function($handler->getAttribute('name'));
				if ($method == 'open' && $handler->childExists('duplicat'))
				{
					if ($duplicat)
					{
						$this->duplicat_content($add_node);
					}
					$duplicat = TRUE;
				}
				else if (!in_array($method, array('find', 'replace', 'after', 'before', 'delete')) || ($method == 'open' && !$handler->childExists('duplicat')))
				{
					if ($duplicat)
					{
						$this->duplicat_content($add_node);
					}
					$duplicat = FALSE;
				}

				// Si duplication, on ajoute des nodes à l'arbre XML
				if ($duplicat)
				{
					$add_node[] = $handler;
				}
			}
		}
	}

	/*
	** Duplique un contenu autant de fois qu'il y a de dossier dans le répertoire concerné
	** -----
	** $list_node ::		Liste des nodes à dupliquer
	*/
	private function duplicat_content(&$list_node)
	{
		// On récupère tout d'abord le dossier à dupliquer
		$dir = $list_node[0]->duplicat[0]->getData();

		// On récupère ensuite le fichier qui va être modifié, pour savoir le répertoire par défaut à ne pas dupliquer
		$default_file = $list_node[0]->file[0]->getData();
		$split_file = explode('/', $default_file);
		$default_dir = $split_file[1];

		// On parcourt maintenant la liste des dossiers du duplicat
		$fd = opendir(ROOT . $dir);
		while ($file = readdir($fd))
		{
			if ($file != '.' && $file != '..' && is_dir(ROOT . $dir . $file) && $file != $default_dir)
			{
				// On peut désormais dupliquer les nodes
				foreach ($list_node AS $node)
				{
					// On met à jour l'élément <file> si on est sur une instruction open
					$pos = count($this->xml->document->instruction[0]->line) - 1;
					$new_node = clone($node);
					if ($this->convert_to_valid_function($node->getAttribute('name')) == 'open')
					{
						$new_file = preg_replace('#' . $dir . $default_dir . '/#', $dir . $file . '/', $node->file[0]->getData());
						$this->xml->document->instruction[0]->appendXmlChild('<line name="open"><file>' . $new_file . '</file></line>', $pos);
					}
					else
					{
						$this->xml->document->instruction[0]->appendChild($new_node, $pos);
					}
				}
			}
		}
		closedir($fd);

		// On vide la liste de nodes
		$list_node = array();
	}

	/*
	** Installe le MOD
	*/
	public function install()
	{
		foreach ($this->xml->document->instruction[0]->line AS $this->handler)
		{
			$method = $this->convert_to_valid_function($this->handler->getAttribute('name'));
			$call_method = 'subparse_' . $method;
			if (method_exists($this, $call_method))
			{
				$this->$call_method();
			}
		}
		$this->subparse_end();

		// remise à 0 des variables
		$this->file_open = $this->file_content = $this->find_code = NULL;
	}

	//
	// ========== METHODES D'INSTALLATION DU MOD ==========
	//

	/*
	** Ouvre un fichier
	*/
	private function subparse_open()
	{
		if ($this->file_open)
		{
			$this->close_file();
		}

		$this->file_open = ROOT . $this->handler->file[0]->getData();
		if (!$this->get_config('install') && (!file_exists($this->file_open) || !is_readable($this->file_open)))
		{
			$this->error(self::MOD_ERROR_FILE_NOT_FOUND, $this->file_open);
			$this->file_open = NULL;
		}
		else
		{
			// Contenu du fichier
			$this->file_content = str_replace(array("\r\n", "\r"), array("\n", "\n"), file_get_contents($this->file_open));
		}
	}

	/*
	** Cherche le code dans un fichier
	*/
	private function subparse_find()
	{
		if ($this->file_open)
		{
			$this->find_code = str_replace(array("\r\n", "\r"), array("\n", "\n"), $this->handler->code[0]->getData());
			if (!preg_match('#' . preg_quote($this->find_code, '#') . '#', $this->file_content))
			{
				$this->error(self::MOD_ERROR_CODE_NOT_FOUND, '<pre style="overflow: auto; width: 100%">' . htmlspecialchars($this->find_code) . '</pre>', str_replace('//', '/', $this->file_open));
			}
		}
	}

	/*
	** Remplace le code trouvé par le nouveau code
	*/
	private function subparse_replace()
	{
		if ($this->file_open)
		{
			$code = str_replace(array("\r\n", "\r"), array("\n", "\n"), $this->handler->code[0]->getData());
			$this->file_content = preg_replace('#' . preg_quote($this->find_code, '#') . '#', $code, $this->file_content, 1);
		}
	}

	/*
	** Ajoute le code avant
	*/
	private function subparse_before()
	{
		if ($this->file_open)
		{
			$code = str_replace(array("\r\n", "\r"), array("\n", "\n"), $this->handler->code[0]->getData());
			$this->file_content = preg_replace('#' . preg_quote($this->find_code, '#') . '#', $code . "\n" . $this->find_code, $this->file_content, 1);
		}
	}

	/*
	** Ajoute le code après
	*/
	private function subparse_after()
	{
		if ($this->file_open)
		{
			$code = str_replace(array("\r\n", "\r"), array("\n", "\n"), $this->handler->code[0]->getData());
			$this->file_content = preg_replace('#' . preg_quote($this->find_code, '#') . '#', $this->find_code . "\n" . $code . "\n", $this->file_content, 1);
		}
	}

	/*
	** Ajoute le code après
	*/
	private function subparse_afterline()
	{
		if ($this->file_open)
		{
			$code = str_replace(array("\r\n", "\r"), array("\n", "\n"), $this->handler->code[0]->getData());
			$this->file_content = preg_replace('#' . preg_quote($this->find_code, '#') . '#', $this->find_code . $code, $this->file_content, 1);
		}
	}

	/*
	** Supprime le code
	*/
	private function subparse_delete()
	{
		if ($this->file_open)
		{
			$this->file_content = preg_replace('#' . preg_quote($this->find_code, '#') . '#', '', $this->file_content, 1);
		}
	}

	/*
	** Execute les requètes SQL du MOD
	*/
	private function subparse_sql()
	{
		if ($this->get_config('install'))
		{
			foreach ($this->handler->query AS $sql_handler)
			{
				$query = $sql_handler->getData();
				$query = str_replace('fsb2_', SQL_PREFIX, $query);

				if ($this->get_config('install_sql'))
				{
					if (!Fsb::$db->simple_query($query))
					{
						$this->error(self::MOD_ERROR_SQL, Fsb::$db->sql_error(), $query);
					}
				}
			}
		}
	}

	/*
	** Copie des fichiers depuis le MOD vers le forum
	*/
	private function subparse_copy()
	{
		if ($this->handler->childExists('file'))
		{
			foreach ($this->handler->file AS $file_handler)
			{
				$filename = $file_handler->filename[0]->getData();
				$duplicat = ($file_handler->childExists('duplicat')) ? $file_handler->duplicat[0]->getData() : NULL;
				$directory = ($file_handler->childExists('directory')) ? $file_handler->directory[0]->getData() : NULL;

				if ($duplicat[strlen($duplicat) - 1] != '/')
				{
					$duplicat .= '/';
				}

				// Duplication de fichier = copier un fichier dans plusieurs répertoires similaires, par
				// exemple les langues, thèmes, etc ...
				if ($duplicat)
				{
					// On récupère le chemin du fichier à éditer
					$file_name = substr($filename, strlen($duplicat));
					$exp = explode('/', $file_name);
					unset($exp[0]);
					$file_name = implode('/', $exp);

					// On parcourt le répertoire de duplicat et on copie le fichier autant de fois qu'il y a de répertoires
					$fd = opendir(ROOT . $duplicat);
					while ($file = readdir($fd))
					{
						if ($file[0] != '.' && is_dir(ROOT . $duplicat . '/' . $file))
						{
							$this->copy_file(substr($this->get_config('mod_path'), strlen(ROOT)) . '/root/' . $filename, $duplicat . '/' . $file . '/' . $file_name);
						}
					}
					closedir($fd);
				}
				else
				{
					if ($directory)
					{
						$this->copy_dir(substr($this->get_config('mod_path'), strlen(ROOT)) . '/root/' . $filename, $filename);
					}
					else
					{
						$this->copy_file(substr($this->get_config('mod_path'), strlen(ROOT)) . '/root/' . $filename, $filename);
					}
				}
			}
		}
	}

	/*
	** Execute un fichier / du code PHP
	*/
	private function subparse_exec()
	{
		if ($this->get_config('install'))
		{
			$code = str_replace(array("\r\n", "\r"), array("\n", "\n"), $this->handler->code[0]->getData());
			$filename = $this->handler->file[0]->getData();

			if ($this->get_config('install_file'))
			{
				// Si on execute un fichier on l'ouvre et on parse le code, pour ensuite l'évaluer
				if ($filename)
				{
					$fd = fopen(ROOT . $filename, 'r');
					$content = trim(fread($fd, filesize(ROOT . $filename)));
					fclose($fd);

					$content = preg_replace('#^<\?(php)?#', '', $content);
					$content = preg_replace('#\?>$#', '', $content);
					$content = preg_replace('#/\*\s*begin include\s*\*/.*?/\*\s*end include\s*\*/#si', '', $content);
					$code = $content;
				}

				eval($code);
			}
		}
	}

	/*
	** Termine le MOD
	*/
	private function subparse_end()
	{
		// Un dernier fichier à écrire ?
		if ($this->file_open)
		{
			$this->close_file();
		}
	}

	//
	// ========== METHODES ANEXES ==========
	//

	/*
	** Ajoute une erreur
	*/
	private function error($errno, $errstr, $errstr2 = NULL)
	{
		$this->log_error[] = array(
			'errno' =>		$errno,
			'errstr' =>		$errstr,
			'errstr2' =>	$errstr2,
			'action' =>		(is_object($this->handler)) ? $this->handler->getAttribute('name') : NULL,
		);
	}

	/*
	** Copie le contenu d'un répertoire vers un autre
	** -----
	** $dir_from ::		Répertoire de provenance
	** $dir_to ::		Répertoire de destination
	*/
	private function copy_dir($dir_from, $dir_to)
	{
		$fd = opendir($dir_from);
		while ($file = readdir($fd))
		{
			if ($file[0] != '.')
			{
				if (is_dir($dir_from . '/' . $file))
				{
					$this->copy_dir($dir_from . '/' . $file, $dir_to . '/' . $file);
				}
				else if (is_file($dir_from . '/' . $file))
				{
					$this->copy_file($dir_from . '/' . $file, $dir_to . '/' . $file);
				}
			}
		}
		closedir($fd);
	}

	/*
	** Copie un fichier vers sa destination, en créant les répertoire non existants
	** -----
	** $from ::		Fichier de provenance
	** $to ::		Fichier de destination
	*/
	private function copy_file($from, $to)
	{
		$exp_to = explode('/', dirname($to));
		$path = '';
		foreach ($exp_to AS $dir)
		{
			$path .= $dir . '/';
			if (!is_dir($path))
			{
				$this->file->chmod(substr(dirname($path), 0, strlen(ROOT)), 0777, FALSE);
				if ($this->get_config('install'))
				{
					$this->file->mkdir($path);
				}
				else if (!is_writable(dirname(ROOT . $path)))
				{
					$this->error(self::MOD_ERROR_DIR_NOT_WRITABLE, dirname(ROOT . $path));
				}
			}
		}

		if ($this->get_config('install'))
		{
			return ($this->file->write($to, file_get_contents(ROOT . $from)));
		}
		else if (!file_exists(ROOT . $from))
		{
			$this->error(self::MOD_ERROR_FILE_NOT_FOUND, $from);
		}
	}

	/*
	** Ecrit le code dans le fichier
	*/
	private function close_file()
	{
		$filename = substr($this->file_open, strlen(ROOT));
		if ($this->get_config('install'))
		{
			if (!is_writable(ROOT . $filename))
			{
				$this->file->chmod($filename, 0666);
			}

			$this->file->write($filename, $this->file_content);
			$this->file_open = NULL;
		}
		else if (!$this->file->chmod($filename, 0666, FALSE) && !is_writable($this->file_open))
		{
			$this->error(self::MOD_ERROR_PERMISSION_DENIED, $this->file_open);
		}
	}

	/*
	** Renvoie la liste des fichiers qui vont être modifiés
	** par un open lors de l'installation du MOD
	*/
	public function get_updated_files()
	{
		$files = array();
		foreach ($this->xml->document->instruction[0]->line AS $handler)
		{
			$method = $this->convert_to_valid_function($handler->getAttribute('name'));
			if ($method == 'open')
			{
				$files[] = $handler->file[0]->getData();
			}
		}
		return ($files);
	}

	/*
	** Sauvegarde les fichiers que le MOD va modifier dans un dossier
	** -----
	** $dir ::		Répertoire de destination
	** $ext ::		Type de compression pour les fichiers sauvés (.tar, .tar.gz, .tar.bz2 ou .zip)
	** $files ::	Tableau contenant les fichiers a sauver
	*/
	public function save_files($dir, $ext = 'zip', $files = NULL)
	{
		if ($files == NULL)
		{
			$files = $this->get_updated_files();
		}

		// Création d'un fichier log.mod pour stoquer les informations sur le backup
		$this->file->write('mod.log', $this->get_config('mod_name') . "\n");
		$files[] = 'mod.log';

		$filename = 'save_' . date("d_m_y_H_i_s", CURRENT_TIME);
		switch ($ext)
		{
			case 'tar' :
			case 'tar.gz' :
			case 'zip' :
				$compress = new Compress($dir . '/' . $filename . '.' . $ext, $this->file);
				foreach ($files AS $file)
				{
					if (file_exists(ROOT . $file))
					{
						$compress->add_file($file);
					}
				}
				$compress->write();
			break;

			default :
				$this->set_config('install', TRUE);
				$save_dir = $dir . '/' . $filename . '/';
				foreach ($files AS $file)
				{
					if (file_exists(ROOT . $file))
					{
						$this->copy_file($file, $save_dir . preg_replace('#^' . ROOT . '#', '', $file));
					}
				}
				$this->set_config('install', FALSE);
			break;
		}
		$this->file->unlink('mod.log');
	}

	/*
	** Convertit un mot clef en un mot universel pour la classe
	** -----
	** $function ::		Mot clef a convertir
	*/
	public function convert_to_valid_function($keyword)
	{
		switch (strtolower($keyword))
		{
			case "ouvrir" :
			case "open" :
				return ('open');

			case "chercher" :
			case "trouver" :
			case "dans la ligne chercher" :
			case "dans la ligne trouver" :
			case "search" :
			case "find" :
			case "in line search" :
			case "in line find" :
				return ('find');

			case "remplacer par" :
			case "remplacer" :
			case "replace by" :
			case "replace" :
				return ('replace');

			case "ajouter apres" :
			case "apres ajouter" :
			case "after add" :
			case "after" :
				return ('after');

			case "dans la ligne ajouter" :
			case "ajouter dans la ligne" :
			case "in line add" :
				return ('afterline');

			case "ajouter avant" :
			case "avant ajouter" :
			case "before add" :
			case "before" :
				return ('before');

			case "supprimer" :
			case "delete" :
				return ('delete');

			case "sql" :
			case "requete sql" :
			case "sql query" :
				return ('sql');

			case "copier" :
			case "copy" :
				return ('copy');

			case "fin" :
			case "fin du mod" :
			case "end" :
			case "end of mod" :
				return ('end');
			
			case 'php' :
			case 'exécuter' :
			case 'exec' :
				return ('exec');

			default :
				$this->error(self::MOD_ERROR_UNKNOWN_INSTRUCTION, $keyword);
				return (NULL);
		}
	}
}

/* EOF */