<?php
/**
 * See fastback.ini.sample for settings
 */
declare(ticks=1);

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

class fastback {

	// Folder path to cache directory. sqlite and thumbnails will be stored here
	// Optional, will create a cache folder in the currend directory as the default
	var $filecache;

	// URL path to cache directory. 
	// Optional, will use current web path + cache as default
	var $cacheurl;

	// File path to full sized photos
	// Optional, will use current directory as default
	var $photobase;

	// URL path to full sized photos
	// Optional, will use current web path as default
	var $photourl;

	// Only show dates on photos when it's a new month
	var $limitdates = true;

	var $db_lock;

	// Max number of thumbnails to reserve per child process
	var $process_limit = 50;

	// Max number of child processes
	var $cores = 5;

	var $debug = false;

	var $supported_photo_types = array(
		// Photo formats
		'png',
		'jpg',
		'heic',
		'jpeg',
		'bmp',
		'gif',
		'tif',
		'heic',
	);
		
	var $supported_video_types = array(
		// Video formats
		'3gp',
		'avi',
		'm4v',
		'mov',
		'mp4',
		'mpeg',
		'mpg',
		'ogg',
		'vob'
	);

	var $meta =array();

	var $sql;

	var $sortorder = 'DESC';


	/**
	 * Kick it off
	 */
	function __construct(){

		$this->filecache = __DIR__ . '/cache/';
		$this->cacheurl = dirname($_SERVER['SCRIPT_NAME']) . '/cache/';
		$this->photobase = __DIR__ . '/';
		$this->photourl = dirname($_SERVER['SCRIPT_NAME']) . '/';
		$this->staticurl = dirname($_SERVER['SCRIPT_NAME']) . '/';

		if ( !empty($_GET['debug']) && $_GET['debug'] === 'true' ) {
			$this->debug = true;
		}

		if ( file_exists(__DIR__ . '/fastback.ini') ) {
			$settings = parse_ini_file(__DIR__ . '/fastback.ini');
			foreach($settings as $k => $v) {
				$this->$k = $v;
			}
		}

		// Ensure single trailing slashes
		$this->filecache = rtrim($this->filecache,'/') . '/';
		$this->cacheurl = rtrim($this->cacheurl,'/') . '/';
		$this->photobase = rtrim($this->photobase,'/') . '/';
		$this->photourl = rtrim($this->photourl,'/') . '/';
		$this->staticurl = rtrim($this->staticurl,'/') . '/';
		$this->sortorder = ($this->sortorder == 'ASC' ? 'ASC' : 'DESC');

		// Hard work should be done via cli
		if (php_sapi_name() === 'cli') {
			$this->load_db_cache();
			$this->make_thumbnails();

			// also regenerate the json
			ob_start();
			@unlink($this->filecache . '/fastback.json.gz');
			$this->sendjson();
			ob_end_clean();

		} else {
			$this->makeoutput();
		}
	}


	/**
	 * Initialize the database
	 */
	public function setup_db() {

		// TODO: Drop these creates in an exception handler
		$q_create_meta = "Create TABLE IF NOT EXISTS fastbackmeta ( key VARCHAR(20) PRIMARY KEY, value VARCHAR(255))";

		$res = $this->sql->query($q_create_meta);
		//var_dump($res);

		$q_create_files = "CREATE TABLE IF NOT EXISTS fastback ( file TEXT PRIMARY KEY, isvideo BOOL, flagged BOOL, mtime INTEGER, sorttime DATETIME, thumbnail TEXT)";

		$res = $this->sql->query($q_create_files);
		//var_dump($res);
        
		$res = $this->sql->query($q_create_files);
	}

	/**
	 * Get all modified files into the db cache
	 */
	public function load_db_cache() {
		global $argv;

		if ( !file_exists($this->filecache) ) {
			mkdir($this->filecache,0700,TRUE);
		}

		$this->sql_connect();

		$lastmod = '19000101';
		if ( !empty($this->meta['lastmod']) ){
			$lastmod = $this->meta['lastmod'];
		}

		if ( count($argv) > 1 && $argv[1] == 'reset' ) {
			$lastmod = '19000101';
			$this->sql->query("DELETE FROM fastback");
		}

		chdir($this->photobase);
		print "Searching for files in " . getcwd() . "\n";
		$filetypes = implode('\|',array_merge($this->supported_photo_types, $this->supported_video_types));
		$cmd = 'find . -type f -regextype sed -iregex  "./[0-9]\{4\}/[0-9]\{2\}/[0-9]\{2\}/.*\(' . $filetypes . '\)$" -newerat ' . $lastmod;
		echo $cmd . "\n";
		$modified_files_str = `$cmd`;
		//print "$cmd\n";
		$modified_files = explode("\n",$modified_files_str);
		$modified_files = array_filter($modified_files);

		print "Building cache for " . count($modified_files) . " files modified since $lastmod\n";
		flush();

		$today = date('Ymd');
		$multi_insert = "INSERT INTO fastback (file,mtime,sorttime,isvideo) VALUES ";
		$multi_insert_tail = " ON CONFLICT(file) DO UPDATE SET isvideo=";
		$collect_photo = array();
		$collect_video = array();
		$togo = count($modified_files);
		foreach($modified_files as $k => $one_file){
			$mtime = filemtime($one_file);
			$pathinfo = pathinfo($one_file);

			if ( empty($pathinfo['extension']) ) {
				print_r($pathinfo);
				var_dump($one_file);
				die("No file extension. Weird.");
				continue;
			}

			if ( in_array(strtolower($pathinfo['extension']),$this->supported_video_types) ) {
				$collect_video[] = "('" . SQLite3::escapeString($one_file) . "','" . SQLite3::escapeString($mtime) . "','" . SQLite3::escapeString(preg_replace('|.*([0-9]{4})/([0-9]{2})/([0-9]{2})/.*|','\1-\2-\3',$one_file)) . "',1)";
			} else if ( in_array(strtolower($pathinfo['extension']),$this->supported_photo_types) ) {
				$collect_photo[] = "('" . SQLite3::escapeString($one_file) . "','" . SQLite3::escapeString($mtime) . "','" . SQLite3::escapeString(preg_replace('|.*([0-9]{4})/([0-9]{2})/([0-9]{2})/.*|','\1-\2-\3',$one_file)) . "',0)";
			} else {
				error_log("Don't know what to do with " . print_r($pathinfo,true));
			}

			if ( count($collect_photo) >= $this->process_limit) {
				$sql = $multi_insert . implode(",",$collect_photo) . $multi_insert_tail . '0';
				$this->sql->query($sql);
				$collect_photo = array();
				$togo -= $this->process_limit;
				print "Upserted {$this->process_limit}, $togo left to go\n";
			}

			if ( count($collect_video) >= $this->process_limit) {
				$sql = $multi_insert . implode(",",$collect_video) . $multi_insert_tail . '1';
				$this->sql->query($sql);
				$collect_video = array();
				$togo -= $this->process_limit;
				print "Upserted {$this->process_limit}, $togo left to go\n";
			}
		}

		if ( count($collect_photo) > 0 ) {
			$sql = $multi_insert . implode(",",$collect_photo) . $multi_insert_tail . '0';
			$this->sql->query($sql);
			$togo -= count($collect_photo);
			$collect_photo = array();
			print "Upserted some, $togo left to go\n";
		}

		if ( count($collect_video) > 0 ) {
			$sql = $multi_insert . implode(",",$collect_video) . $multi_insert_tail . '1';
			$this->sql->query($sql);
			$togo -= count($collect_video);
			$collect_video = array();
			print "Upserted some, $togo left to go\n";
		}

		$this->sql->query("INSERT INTO fastbackmeta (key,value) values ('lastmod',".date('Ymd').") ON CONFLICT(key) DO UPDATE SET value=".date('Ymd'));
		$this->sql_disconnect();
	}

	/**
	 * Build thumbnails in parallel
	 *
	 * This is the parent process
	 */
	public function make_thumbnails() {

		$this->sql_connect();

		$this->sql->query("UPDATE fastback SET thumbnail=NULL WHERE thumbnail LIKE 'RESERVED%'");

		$this->sql_disconnect();

		// Make the children
		$children = array();
		for ($i = 0;$i < $this->cores; $i++){
			switch($pid = pcntl_fork()){
				case -1:
					die("Forking failed");
					break;
				case 0:
					// This is a child
					$this->_make_thumbnails($i);
					exit();
					break;
				default:
					$children[] = $pid;
					// This is the parent
			}
		}

		// Reap the children
		while(count($children) > 0){
			foreach($children as $key => $child){
				$res = pcntl_waitpid($child, $status, WNOHANG);
				if($res == -1 || $res > 0) {
					unset($children[$key]);
				}
			}
			$this->sql_connect();
			$res = $this->sql->querySingle("SELECT COUNT(*) FROM fastback WHERE thumbnail IS NULL AND flagged IS NOT TRUE",);
			print "PARENT: $res more to go\n";
			$this->sql_disconnect();
			sleep(1);
		}
	}

	private function _make_thumbnails($childno = "Unknown") {
		echo "Child $childno pid is " . getmypid() . "\n";

		do {
			$queue = array();
			$this->sql_connect();
			$res = $this->sql->query("UPDATE fastback SET thumbnail='RESERVED-" . getmypid() . "' WHERE flagged IS NOT TRUE AND thumbnail IS NULL AND FILE != '' LIMIT " . $this->process_limit);
			$q_queue = "SELECT file FROM fastback WHERE thumbnail='RESERVED-" . getmypid() . "'";
			$res = $this->sql->query($q_queue);
			while($row = $res->fetchArray(SQLITE3_ASSOC)){
				$queue[] = $row['file'];
			}
			$this->sql_disconnect();

			echo "\nChild $childno (" . getmypid() . ") reserved " . count($queue) . " images\n";

			if ( count($queue) === 0 ) {
				print "Child $childno exiting\n";
				exit();
			}

			$made_thumbs = array();
			while($file = array_pop($queue)){

				$thumbnailfile = $this->filecache . '/' . ltrim($file,'./') . '.jpg';

				// Make it if needed
				if ( !file_exists($thumbnailfile) ) {
					$dirname = dirname($thumbnailfile);
					if (!file_exists($dirname) ){
						@mkdir($dirname,0700,TRUE);
					}

					$shellfile = escapeshellarg($file);
					$shellthumb = escapeshellarg($thumbnailfile);
					$pathinfo = pathinfo($file);

					if (in_array(strtolower($pathinfo['extension']),$this->supported_photo_types)){
						$cmd = "vipsthumbnail --size=120x120 --output=$shellthumb --smartcrop=attention $shellfile";
						echo "\tChild $childno -- $cmd\n";
						$res = `$cmd`;
					} else if ( in_array(strtolower($pathinfo['extension']),$this->supported_video_types) ) {

						$tmpthumb = $this->filecache . 'tmpthumb_' . getmypid() . '.jpg';
						$tmpshellthumb = escapeshellarg($tmpthumb);

						$cmd = "ffmpeg -y -ss 10 -i $shellfile -vframes 1 $tmpshellthumb 2>&1 > /tmp/fastback.ffmpeg.log.$childno";
						echo "\tChild $childno -- $cmd\n";
						$res = `$cmd`;

						if ( !file_exists($tmpthumb)) {
							$cmd = "ffmpeg -y -ss 2 -i $shellfile -vframes 1 $tmpshellthumb 2>&1 > /tmp/fastback.ffmpeg.log.$childno";
							echo "\tChild $childno -- $cmd\n";
							$res = `$cmd`;
						}

						if ( !file_exists($tmpthumb)) {
							$cmd = "ffmpeg -y -ss 00:00:00 -i $shellfile -frames:v 1 $tmpshellthumb 2>&1 > /tmp/fastback.ffmpeg.log.$childno";
							echo "\tChild $childno -- $cmd\n";
							$res = `$cmd`;
						}

						if ( file_exists($tmpthumb) ) {
							$cmd = "vipsthumbnail --size=120x120 --output=$shellthumb --smartcrop=attention $tmpshellthumb";
							echo "\tChild $childno -- $cmd\n";
							$res = `$cmd`;
							unlink($tmpthumb);
						}

					} else {
						print "What do I do with ";
						print_r($pathinfo);
					}

					if ( file_exists( $thumbnailfile ) ) {
						$cmd = "jpegoptim --strip-all --strip-exif --strip-iptc $shellthumb";
						echo "\tChild $childno -- $cmd\n";
						$res = `$cmd`;
					} 
				}

				// If we've got the file, we're good
				if ( file_exists($thumbnailfile) ) {
					$made_thumbs[$file] = $thumbnailfile;
				}
			}
			print "Done with while loop\n";

			if (count($made_thumbs) > 0){
				$this->sql_connect();
				$update_q = "UPDATE fastback SET thumbnail=CASE \n";
				foreach($made_thumbs as $file => $thumb){
					$update_q .= " WHEN file='" . SQLite3::escapeString($file) . "' THEN '" . SQLite3::escapeString($thumb) . "'\n";
				}
				$update_q .= " ELSE thumbnail END
					WHERE thumbnail='RESERVED-" . getmypid() . "'";
				$this->sql->query($update_q);
				$this->sql_disconnect();
			}

		} while (count($made_thumbs) > 0);

		@unlink($this->filecache . '/fastback.json');
	}

	private function sql_connect($try_no = 1){
		if (php_sapi_name() === 'cli') {
			$this->db_lock = fopen($this->filecache . '/fastback.lock','w');
			if( flock($this->db_lock,LOCK_EX)){
				$this->sql = new SQLite3($this->filecache . '/fastback.sqlite');
			} else {
				throw new Exception("Couldn't lock db");
			}

			$this->setup_db();
		} else {
			$this->sql = new SQLite3($this->filecache .'/fastback.sqlite');
		}

		if (empty($this->meta)){
			$this->load_meta();
		}
	}

	private function sql_disconnect(){
		$this->sql->close();
		if (!empty($this->db_lock) ) {
			flock($this->db_lock,LOCK_UN);
			fclose($this->db_lock);
		}
		unset($this->sql);
	}

	// One file with various output options, depending on the $_GET flags
	public function makeoutput() {
		if (!empty($_GET['get']) && $_GET['get'] == 'photojson'){
			$this->sendjson();
		} else if (!empty($_GET['get']) && $_GET['get'] == 'js') {
			$this->makejs();
        } else if (!empty($_GET['flag'])) {
            $this->flag_photo();
        } else if (!empty($_GET['test'])) {
            $this->test();
		} else if (!empty($_GET['proxy'])) {
			$this->proxy();
		} else {
			$this->makehtml();
		}
	}

	/**
	 * Generate or send a cached version of the photo json
	 */
	public function sendjson() {
		$json = array(
			'yearmonthindex' => array(),
			'tags' => array(),
			);

		$this->sql_connect();
		$cf = $this->filecache . '/fastback.json.gz';
		@header("Cache-Control: \"max-age=1209600, public");
		@header("Content-Type: application/json");
		@header("Content-Encoding: gzip");
		if (file_exists($cf)) {
			header('Content-Length: ' . filesize($cf));
			readfile($cf);
			exit();
		}

		$res = $this->sql->query("SELECT file,sorttime,isvideo FROM fastback WHERE thumbnail IS NOT NULL AND thumbnail NOT LIKE 'RESERVE%' AND flagged IS NOT TRUE ORDER BY sorttime " . $this->sortorder . ",file");
		$last_date = NULL;
		$last_year = NULL;
		$idx = 0;
		while($row = $res->fetchArray(SQLITE3_ASSOC)){
			if ( $last_date != $row['sorttime'] ) {
				$last_date = $row['sorttime'];

				$this_year_month = substr($last_date,0,7);

				if (empty($json['yearmonthindex'][$this_year_month])) {
					$json['yearmonthindex'][$this_year_month] = $idx;
				}
			}
            $base = basename($row['file']);
			$json['tags'][] = '<div class="tn y' . substr($row['sorttime'],0,4) . ' m' . substr($row['sorttime'],5,2) . ( $row['isvideo'] ? ' vid' : '') . '" data-d=' . $row['sorttime'] . ' id=p' . $idx . '><img loading=lazy  src="' . htmlentities(substr($row['file'],2)) . '.jpg" alt="' . $base . '"></div>';
			$idx++;
		}

		$this->sql_disconnect();

		$str = json_encode($json,JSON_PRETTY_PRINT);
		@file_put_contents('compress.zlib://' . $cf,$str);
		print($str);
	}

	/**
	 * Generate the output html for the webpage, including any dynamicly generated CSS or HTML
	 */
	public function makehtml(){
		$html = '<!DOCTYPE html>
<html lang="en">
	<head>
		<meta charset="UTF-8">
		<base href="'. $this->cacheurl . '/">
		<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0">
		<link rel="shortcut icon" href="' . $this->staticurl . '/fastback_assets/favicon.png' . ($this->debug ? '?ts=' . time() : '') . '"> 
		<link rel="apple-touch-icon" href="' . $this->staticurl . '/fastback_assets/favicon.png' . ($this->debug ? '?ts=' . time() : '') . '">
		<title>Moore Photos</title>
		<link rel="stylesheet" href="'. $this->staticurl .'/fastback_assets/fastback.css' . ($this->debug ? '?ts=' . time() : '') . '">
		<!-- Powered by https://github.com/stuporglue/fastback/ -->
    </head>
	<body>
		<div class="photos" id="photos"></div>
		<div class="scroller"></div>
		<div class="calendarpick"><div class="year"></div><div class="calendar">
			<div class="calendarrow">
				<div id="calpick-jan">Jan</div>
				<div id="calpick-feb">Feb</div>
				<div id="calpick-mar">Mar</div>
			</div>
			<div class="calendarrow">
				<div id="calpick-apr">Apr</div>
				<div id="calpick-may">May</div>
				<div id="calpick-jun">Jun</div>
			</div>
			<div class="calendarrow">
				<div id="calpick-jul">Jul</div>
				<div id="calpick-aug">Aug</div>
				<div id="calpick-sep">Sep</div>
			</div>
			<div class="calendarrow">
				<div id="calpick-oct">Oct</div>
				<div id="calpick-nov">Nov</div>
				<div id="calpick-dec">Dec</div>
			</div>
		</div></div>
		<div id="resizer">
			<input type="range" min="1" max="10" value="5" class="slider" id="zoom">
		</div>
		<div id="notification"></div>
		<div id="thumb" data-ythreshold=150><div id="thumbcontent"></div><div id="thumbcontrols"></div><div id="thumbclose">🆇</div><div id="thumbleft" class="thumbctrl">LEFT</div><div id="thumbright" class="thumbctrl">RIGHT</div></div>
	<script src="'. $this->staticurl .'/fastback_assets/jquery.min.js' . ($this->debug ? '?ts=' . time() : '') . '"></script>

	<script src="'.$this->staticurl.'/fastback_assets/hammer.js' . ($this->debug ? '?ts=' . time() : '') . '"></script>
	<script src="'.$this->staticurl.'/fastback_assets/jquery.hammer.js' . ($this->debug ? '?ts=' . time() : '') . '"></script>

	<script src="'.$this->staticurl.'/fastback_assets/fastback.js' . ($this->debug ? '?ts=' . time() : '') . '"></script>
	<script>
		var FastbackBase = "' . $_SERVER['SCRIPT_NAME'] . '";
		var FastbackBase = "' . $_SERVER['SCRIPT_NAME'] . '";
		var fastback = new Fastback({
			cacheurl: "' . $this->cacheurl . '",
			photourl: "' . $this->photourl . '",
			staticurl: "' . $this->staticurl . '",
			fastbackurl: "' . $_SERVER['SCRIPT_NAME'] . '",
			debug: ' . ($this->debug ? 'true' : 'false'). ',
			limitdates: ' . ($this->limitdates ? 'true' : 'false') . '
		});
	</script>

	</body>
</html>';
		print $html;
	}

	private function load_meta() {
		$q_getallmeta = "SELECT key,value FROM fastbackmeta";
		$res = $this->sql->query($q_getallmeta);
		//var_dump($res);
		$this->meta = array();
		while($row = $res->fetchArray(SQLITE3_ASSOC)){
			$this->meta[$row['key']] = $row['value'];
		}
	}

    public function flag_photo(){
        $photo = $_GET['flag'];
		$this->sql_connect();
        $stmt = $this->sql->prepare("UPDATE fastback SET flagged=1 WHERE file=:file");
        $stmt->bindValue(':file',$_GET['flag']);
        $stmt->execute();
        $this->sql_disconnect();
		header("Content-Type: application/json");
		header("Cache-Control: no-cache");
        print json_encode(array('file_flagged' => $_GET['flag']));
    }

	public function test() {
	}

	public function proxy() {
		$file = $_GET['proxy'];

		if ( strpos($file,$this->photobase) !== 0 ) {
			die("Only photos in photobase can be proxied");
		}

		if ( !file_exists($file) ) {
			print $file . "\n";
			die("File doesn't exist");
		}

		$mime = mime_content_type($file);
		$mime = explode('/',$mime);

		if ( $mime[1] == 'x-tga' ) {
			$mime[0] = 'video';
			$mime[1] = 'mpeg2';
		}

		if ( $mime[0] == 'image' ) {
			header("Content-Type: image/jpeg");
			$cmd = 'convert ' . escapeshellarg($file) . ' JPG:-';
			passthru($cmd);
		} else if ($mime[0] == 'video' ) {
			header("Content-Type: image/jpeg");
			$cmd = "ffmpeg -ss 00:00:00 -i " . escapeshellarg($file) . " -frames:v 1 -f singlejpeg - ";
			passthru($cmd);
		} else {
			die("Unsupported file type");
		}
	}
}

new fastback();