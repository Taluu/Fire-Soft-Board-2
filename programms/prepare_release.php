<?php
/*
** +---------------------------------------------------+
** | Name :		~/programms/prepare_release.php
** | Begin :	25/12/2006
** | Last :		25/12/2007
** | User :		Genova
** | Project :	Fire-Soft-Board 2 - Copyright FSB group
** | License :	GPL v2.0
** +---------------------------------------------------+
*/

//die('Pour pouvoir utiliser ce fichier veuillez decommenter cette ligne. <b>Cefichier est une faille potentielle de s�curit�</b>, ne l\'utilisez qu\'en local, ou si vous �tes certain de ce que vous faites');

/*
** Supprimes les fichiers mis en cache, ainsi que ces foutus Thumbs.db, vide le fichier config, pr�pare les index.html, etc ...
** Fait pas le caf� par contre :(
*/

function delete_like($path, $end)
{
	$fd = opendir($path);
	while ($file = readdir($fd))
	{
		if ($file != '.' && $file != '..' && is_file($path . $file))
		{
			if (preg_match('#' . preg_quote($end, '#') . '$#i', $file))
			{
				unlink($path . $file);
			}
		}
	}
	closedir($fd);
}

// J'avais limite envie d'appeler cette fonction delete_fucking_thumbs() ^^
function delete_thumbs($path)
{
	$fd = opendir($path);
	while ($file = readdir($fd))
	{
		if ($file != '.' && $file != '..')
		{
			if (is_dir($path . $file))
			{
				delete_thumbs($path . $file . '/');
			}
			else if ($file == 'Thumbs.db')
			{
				unlink($path . 'Thumbs.db');
			}
		}
	}
	closedir($fd);
}

function truncate_config()
{
	$fd = fopen('../config/config.php', 'w');
	fclose($fd);
}

function set_index_html($path)
{
	if (!file_exists($path . 'index.php'))
	{
		$content = file_get_contents('index.html');
		$fd2 = fopen($path . 'index.html', 'w');
		fwrite($fd2, $content);
		fclose($fd2);
	}

	$fd = opendir($path);
	while ($file = readdir($fd))
	{
		if ($file != '.' && $file != '..')
		{
			if (is_dir($path . $file))
			{
				set_index_html($path . $file . '/');
			}
		}
	}
	closedir($fd);
}

// Supprimes les fichiers en cache
delete_like('../cache/sql/', '.php');
delete_like('../cache/tpl/', '.php');
delete_like('../cache/xml/', '.php');
delete_like('../cache/diff/', '.php');
delete_like('../upload/', '.file');
delete_like('../mods/save/', '.tar.gz');
delete_like('../mods/save/', '.zip');

// Supprime les Thumbs.db
delete_thumbs('../');

// Vide le fichier de configuration
truncate_config();

// Met un index.html dans tous les r�pertoires en ayant besoin
set_index_html('../');

?>