#!/usr/bin/php
<?php
error_reporting(0);

function print_usage() {
	echo PHP_EOL;
	echo "Usage: php ./wp-verify.php <wordpress-path> [<tmp-path>]". PHP_EOL;
	echo PHP_EOL;
}

if (($argc < 2) || ($argv[1] == "-h") || ($argv[1] == "--help")){
	print_usage();
	exit(-1);
}


$wordpress_path = $argv[1];

$data_dir = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR. uniqid('wp-verify-');
if ($argc == 3) {
	$data_dir = $argv[2]; 
	if (!file_exists($data_dir)) {	
		$result = @mkdir($data_dir);
		if (!$result) {
			echo "Data directory does not exist, and cannot be created." . PHP_EOL;
			exit(-1);
		}
	}
} else {
	mkdir($data_dir);
	echo "Created temporary directory: " .$data_dir . PHP_EOL;	
}

if ( !(file_exists($wordpress_path) && is_dir($wordpress_path))) {
	echo "ERROR: path $wordpress_path not found". PHP_EOL;
	print_usage();
	exit(-1);
}


$config_file = $wordpress_path . DIRECTORY_SEPARATOR . 'wp-includes/version.php';

if (!file_exists($config_file)) {
	echo "ERROR: $config_file not found". PHP_EOL;
	echo "       are you sure this is a wordpress directory?". PHP_EOL;
	print_usage();
	exit(-1);
}

require($config_file);



$wp_file = $data_dir . DIRECTORY_SEPARATOR . "wordpress-$wp_version.tar.gz";
if (!file_exists($wp_file)) {
	echo "Retrieving Wordpress $wp_version...";

	$start = time();
	$wp = file_get_contents("http://wordpress.org/wordpress-$wp_version.tar.gz");
	$end = time();
	file_put_contents($wp_file, $wp);
	unset($wp);
	$kbs = floor((filesize($wp_file)/1024)/($end - $start));
	
	echo "... done! ({$kbs}KB/s)" . PHP_EOL;
}


echo "Verifying wordpress download...";
$md5 = trim(file_get_contents("http://wordpress.org/wordpress-$wp_version.md5"));
$md5_file = md5_file($wp_file);
if ($md5 == $md5_file) {
    echo "... verified!" . PHP_EOL;
} else {
    echo "... failed! ($md5 does not match $md5_file)" . PHP_EOL;
    echo "remove $wp_file and run this again". PHP_EOL;
    exit(-1);
}

echo "Unpacking Wordpress...";
$file_dir = $data_dir . DIRECTORY_SEPARATOR . "wordpress-$wp_version";
if (file_exists($file_dir)) {
    echo "... failed! (Unpack directory already exists: $file_dir)" . PHP_EOL;
    exit(-1);
} else {
    mkdir($file_dir);
}

`cd $file_dir && tar -zxf $wp_file > /dev/null`;
echo "... complete!" . PHP_EOL;


$remote_file_dir = $file_dir . '-remote';


echo "Generating MD5sums...";

try {
	$rdi = new RecursiveDirectoryIterator($file_dir . DIRECTORY_SEPARATOR . 'wordpress');
	$wp = new RecursiveIteratorIterator($rdi);
} catch (Exception $e) {
	echo $e;
}

$i = 0;

// $ignored_extensions = array('jpg', 'gif', 'png', 'pdf', 'swf', 'pot', 'txt', 'html', 'css', 'htm', 'zip');
$ignored_extensions = array();

foreach ($wp as $file) {
    if (!$file->isFile() || basename($file->getFileName()) == 'wp-config-sample.php') {
        continue;
    }
	
	$ext = pathinfo($file->getFileName(), PATHINFO_EXTENSION);
	if (in_array($ext, $ignored_extensions)) {
		continue;
	}

    $i++;
    if ($i % 10 == 0) {
        echo '.';
    }

    $filename = str_replace($file_dir . DIRECTORY_SEPARATOR . 'wordpress', '', $file->getPathname());
    // Strip slash and ./
    if ($filename{0} == '/') {
        $filename = substr($filename, 1);
    } elseif ($filename{0} == '.') {
        $filename = substr($filename, 2);
    }

    $md5sums[$filename] = md5_file($file->getPathname());
}
echo "... done!" . PHP_EOL;

echo "Comparing " .sizeof($md5sums). " files...";
$i = 0;
if (file_exists($remote_file_dir)) {
    echo "... failed! (Temp directory already exist: $remote_file_dir)";
    exit(-1);
}


$failed = array();
$i = 0;
foreach (array_keys($md5sums) as $file) {
    $i++;

	if ($i % 10 == 0) {
		echo '.';
	}

    if (md5_file($wordpress_path . $file) != $md5sums[$file]) {
		//echo "". md5_file($wordpress_path . $file) . " != ". $md5sums[$file] ."\n";
        
        $failed[] = array($file);
    }


}
echo "... complete!" . PHP_EOL;

if ($failed) {

	echo "Failed files:" . PHP_EOL;
	foreach($failed as $item) {
		echo "   " . $item[0] . PHP_EOL;
	}
	echo PHP_EOL;

} else {
    echo "Wordpress install is pristine!" . PHP_EOL;
}

// clean up
echo "removing $file_dir" . PHP_EOL;
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($file_dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $path) {
    $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
}
rmdir($file_dir);



?>
