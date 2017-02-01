<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
$log="logg.txt";
$file=fopen($log, "a");
ob_start();
echo date("d-m-y H:i:s ", time()) . $_SERVER['REMOTE_ADDR'];

	include('credentials.php');
	$access_token = '';
	load_token();
	
	// Mysql
	$mysqli = mysqli_connect("localhost", $mysql_login, $mysql_password, $mysql_db);
	if (!$mysqli) die('ошибка mysql');
	$table_name = "newest";
	
	// HTML
	$m_latest = mysqli_query($mysqli, "SELECT url, last_check FROM latest");
	if ($m_latest == null) echo "LATEST==NULL! ";
	$latest = mysqli_fetch_row($m_latest);
	mysqli_free_result($m_latest);
	$last_check = $latest[1];	// время последней проверки
	$latest = $latest[0];	// URL в последнем посте в паблике
	
	include_once('simple_html_dom.php');
	$html = str_get_html(curl('http://4pda.ru/forum/index.php?act=search&query=&username=&forums[]=all&subforums=1&source=all&sort=dd&result=topics'));
	$html = $html->find('.ipbtable', 2); // Третья по счету табличка
	$queue = array();
	for ($i=2; $i<52; $i++) { // for each row of table
		$tr = $html->find('tr', $i);
		if ($tr != null) {
			$td1 = $tr->find('.row2', 1); // title
			if ($td1 == null) $td1 = $tr->find('.row2shaded', 1);			
			if ($td1 != null) {
				$a_title = $td1->find('a', -1);
				$td3 = $tr->find('.row2', 3);	// answers
				if ($td3 == null) $td3 = $tr->find('.row2', 1); // for shaded case
				$a_answers = $td3->find('a', -1);
				
				$url = $a_title->href;
				$title = mb_convert_encoding($a_title->innertext, 'utf-8', 'Windows-1251');
				$answers = $a_answers->innertext;		
				// *Горячие темы
				do {
					$sql_result = mysqli_query($mysqli, "SELECT url, title, ".$answers." - answers as diff FROM ".$table_name." WHERE url = '".$url."' AND url <> '".$latest."' HAVING diff > 10");
					if (!$sql_result) echo "sql_result == null; " . mysqli_error($mysqli)." ";
				}
				while (!$sql_result);
				if (($row = mysqli_fetch_row($sql_result)) && time()-$last_check<60*9) {			// Если нашлись горячие темы, создаем пост.
					$html_topic = str_get_html(curl('http:'.$url));	// Качаем страничку с темой
					$image = $html_topic->find('.postcolor img.attach, .postcolor img.linked-image', 0);	// Первое уменьшенное изображение
					if ($image == null) $image = $html_topic->find('.attach', 0);	// либо первый аттач
					if ($image == null) $image = $html_topic->find('.linked-image', 0);	// безысходность
					if ($image != null) $fileFrom = $image->src;
					else $fileFrom = "";
					echo "fileFrom: $fileFrom<br>";
					$photo = post_image($fileFrom);					// если есть что загружать, загружаем.
					// Постинг записи на стену
					do ; while (!mysqli_query($mysqli, "UPDATE latest SET url = '" . $url . "'"));
					$method_str = 'wall.post?owner_id=-67811785&message='.urlencode('[Горячее] "'.
					htmlspecialchars_decode($row[1]).'". За 5 минут было оставлено '.$row[2].' сообщений. Теперь их '.$answers.
					'. ').'&attachments='.$photo.urlencode('http:'.$url.'&st='.($answers-$row[2])).'&from_group=1&signed=0&friends_only=0';
					$api_result = api($method_str);
					if (!$api_result) var_dump("apiresult==null; $method_str");
					else var_dump($api_result);						// если запостилась новость
				}
				mysqli_free_result($sql_result);
				
				$queue[] = "('".$url."', '".$title."', ".$answers.")";
			}
		}
	}
	do ; while (!mysqli_query($mysqli, "TRUNCATE TABLE ".$table_name));
	do ; while (!mysqli_query($mysqli, "INSERT INTO ".$table_name." (url, title, answers) VALUES ".implode(', ', $queue)));
    do ; while (!mysqli_query($mysqli, "UPDATE latest SET last_check = " . time() . ""));

	// *Информационные темы
	$sql_result = mysqli_query($mysqli, "SELECT topic_id, UNIX_TIMESTAMP(last_message), description FROM infotopics");
	if ($sql_result != null) {
		while ($row = mysqli_fetch_row($sql_result)) {	
			$html_topic = str_get_html(curl("http://4pda.ru/forum/index.php?showtopic=".$row[0]."&st=99999999"));// Качаем последнюю страничку с темой
			$last_date = strtotime(str_replace(mb_convert_encoding('Сегодня', 'Windows-1251', 'utf-8'), date('d.m.Y'), $html_topic->find('.row2 text', -1)->plaintext)); // Unixtime последнего сообщения
			if ($last_date > $row[1]) {				
				$message = $html_topic->find('.postcolor', -1);	// Последнее сообщение
				$image = $html_topic->find('.linked-image, img.attach', 0);	// Первое  изображение
				if ($image != null) $fileFrom = $image->src;
				else $fileFrom = "";
				echo "fileFrom: $fileFrom<br>";
				$photo = post_image($fileFrom);					// если есть что загружать, загружаем.
				// Постинг записи на стену
				do ; while (!mysqli_query($mysqli, "UPDATE infotopics SET last_message = FROM_UNIXTIME($last_date) WHERE topic_id = ".$row[0]));
				$method_str = 'wall.post?owner_id=-67811785&message='.urlencode('['.$row[2].']').'%0A'.urlencode(mb_convert_encoding($message->plaintext, 'utf-8', 'Windows-1251')).'&attachments='.$photo.urlencode('http://4pda.ru/forum/index.php?showtopic='.$row[0].'&view=getlastpost').'&from_group=1&signed=0&friends_only=0';
				$api_result = api($method_str);
				var_dump($api_result);
				if ($api_result == null) var_dump($method_str);
			}
		}
	}
	
	/*$youtube = file_get_html("http://gdata.youtube.com/feeds/api/users/4pdaRU/uploads");
	$datetime = explode('T',$youtube->find('published',0)->plaintext);
	$time = explode('.',$datetime[1]);
	$hms = explode(':',$time[0]);
	$ymd = explode('-',$datetime[0]);
	if (mktime()-mktime($hms[0]+3,$hms[1],$hms[2],$ymd[1],$ymd[2],$ymd[0]) <= 300) {
		$method_str = 'wall.post?owner_id=-67811785&message='.
		urlencode('[Youtube] '.$youtube->find('title',1)->plaintext).'%0A'.
		urlencode(htmlspecialchars_decode($youtube->find('entry content',0)->innertext)).
		'&attachments='.urlencode($youtube->find('entry link',0)->href).'&from_group=1&signed=0&friends_only=0';
		$api_result = api($method_str);
		var_dump($api_result);
		if ($api_result == null) var_dump($method_str);
	}*/

	mysqli_close($mysqli);
	fputs($file, ob_get_clean()."\n\n");
	fclose($file);
// -------------------------------------------------------------------------------------------------------------- //
	function post_image( $fileFrom ) {
		if ($fileFrom  == "") return "";
		$filenameExploded = explode('.',basename($fileFrom));
		$uploadToDir = './temp.'.$filenameExploded[count($filenameExploded)-1];
		echo "uploadToDir: $uploadToDir<br>";
		
		/* $curl_file = fopen($uploadToDir, 'w');
		$ch = curl_init($fileFrom);
		curl_setopt($ch, CURLOPT_FILE, $curl_file);
		$upload_result = curl_exec($ch);
		curl_close($ch);
		fclose($curl_file);	 */
		
		//if ($upload_result !== FALSE) {	// Если картинка скачалась
		if (copy($fileFrom, $uploadToDir)) {	// Если картинка скачалась
			// 1. Узнать сервер для загрузки
			$method_str = 'photos.getWallUploadServer?gid=67811785';
			$api_result = api($method_str);
			$upload_url = $api_result['response']['upload_url'];
			// 2. Отправить файл на полученный сервер
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $upload_url);
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			$post = array('photo'=>'@'.realpath($uploadToDir));
			curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
			$curl_result=curl_exec ($ch);
			curl_close ($ch);
			if ($curl_result !== FALSE) {
				$curl_json = json_decode($curl_result, true);
				if (!isset($curl_json["error"])) {
					//3. Сохранить фотки
					$method_str = 'photos.saveWallPhoto?server='.$curl_json['server'].'&photo='.$curl_json['photo'].'&hash='.$curl_json['hash'].'&gid=67811785&from_group=1&signed=0';
					$api_result = api($method_str);
					return $api_result['response'][0]['id'].',';
				}
				else {							// Если картинка не загрузилась на Вконтакт
					echo "upload failed";
					return "";
				}			
			}
			else {							// Если картинка не загрузилась на Вконтакт
				echo "upload failed";
				return "";
			}
		} else {								// Если картинка не скачалась
			echo "copy failed";
			return "";
		}
	}
	
	function load_token($reauth=false) {
	   global $access_token,$acc_token_file;
	   if (!$reauth && file_exists($acc_token_file)) {
		  $s=file_get_contents($acc_token_file);
		  preg_match("/\[([a-f0-9]+)\]/", $s, $matches);
		  if (!$matches[0]){
			 load_token(true);
		  } else {
			 $access_token=$matches[1];
		  }
	   } else {
		  auth_api();
		  if ($access_token==""){
			 echo "Auth Error";
		  } else {
			 file_put_contents($acc_token_file,'<?php if (!defined("vk_online")) die("x_X"); "['.$access_token.']";?>');
		  }
	   }
	}

	function curl( $url ) {
			$ch = curl_init( $url );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false );
			curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );		
			$response = curl_exec( $ch );
			curl_close( $ch );
			return $response;
	}

	function auth_api(){
	   global $access_token,$app,$key,$login,$pass;
	   $auth = curl( "https://oauth.vk.com/token?grant_type=password&client_id=$app&client_secret=$key&username=$login&password=$pass" ); //Авторизация
	   $json = json_decode( $auth, true );
	   $access_token = $json['access_token'];  
	}

	function api($method){
	   global $access_token;
	   $r = curl("https://api.vk.com/method/$method&access_token=$access_token");
	   $json = json_decode( $r, true );
	   if (isset($json['error'])) {
		  $code=$json['error']['error_code'];
		  if ($code==4 || $code==3) {
			 load_token(true);
			 api($method);
		  } else {
             echo "fucking fuck";
			 var_dump($json);
		  }
	   } else {
		  return $json;
	   }
	}
?>
