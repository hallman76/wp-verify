#!/usr/bin/php
<?php
error_reporting(0);
set_include_path('.' . PATH_SEPARATOR . dirname(__FILE__) . PATH_SEPARATOR . get_include_path());
require_once 'cli.sh';
// Try to include system PEAR Archive_TAR
@include 'Archive/TAR.php';
if (!class_exists('Archive_TAR')) {
    // No guarantees this will work
    echo "Warning: Using built-in Archive_TAR! This has not been tested well!" . PHP_EOL;
    include 'Archive/TAR-mini.php';
}

CLI::seto(
    array(
        'd:' => 'Temporary Data directory',
        'w:' => 'Wordpress Path on remote host',
        'v:' => 'Wordpress Version',
		'l' => 'Check Plugins',
		'i:' => 'Diff to file',
    )
);

if ( !CLI::geto('v') || !CLI::geto('w')) {
    CLI::gethelp();
    exit(-1);
}

if (!$data_dir = CLI::geto('d')) {
    $data_dir = realpath(sys_get_temp_dir()) .DIRECTORY_SEPARATOR. uniqid('wp-verify-');
	mkdir($data_dir);
	echo "Created temporary directory: " .$data_dir . PHP_EOL;
} elseif (!file_exists($data_dir)) {
	$result = @mkdir($data_dir);
	if (!$result) {
		echo "Data directory does not exist, and cannot be created." . PHP_EOL;
		exit(-1);
	}
}

$version = CLI::geto('v');

echo "Retrieving Wordpress $version...";
$wp_file = $data_dir . DIRECTORY_SEPARATOR . "wordpress-$version.tar.gz";
if (!file_exists($wp_file)) {
	$start = time();
	$wp = file_get_contents("http://wordpress.org/wordpress-$version.tar.gz");
	$end = time();
	file_put_contents($wp_file, $wp);
	unset($wp);
	$kbs = floor((filesize($wp_file)/1024)/($end - $start));
}

echo "... done! ({$kbs}KB/s)" . PHP_EOL;

echo "Verifying build...";
$md5 = trim(file_get_contents("http://wordpress.org/wordpress-$version.md5"));
$md5_file = md5_file($wp_file);
if ($md5 == $md5_file) {
    echo "... verified!" . PHP_EOL;
} else {
    echo "... failed! ($md5 does not match $md5_file)" . PHP_EOL;
    exit(-1);
}

echo "Unpacking Wordpress...";
$file_dir = $data_dir . DIRECTORY_SEPARATOR . "wordpress-$version";
if (file_exists($file_dir)) {
    echo "... failed! (Unpack directory already exists: $file_dir)" . PHP_EOL;
    exit(-1);
} else {
    mkdir($file_dir);
}
/*$tar = new Archive_Tar($wp_file, 'gz');
$tar->extract($file_dir);*/
`cd $file_dir && tar -zxf $wp_file > /dev/null`;
echo "... complete!" . PHP_EOL;

$wordpress = CLI::geto('w');


if (substr($wordpress, -1) != '/') {
    $wordpress .= '/';
}

if ($wordpress{0} != '/') {
    $wordpress = '/' .$wordpress;
}


$remote_file_dir = $file_dir . '-remote';


echo "Generating MD5sums...";

try {
	$rdi = new RecursiveDirectoryIterator($file_dir . DIRECTORY_SEPARATOR . 'wordpress');
	$wp = new RecursiveIteratorIterator($rdi);
} catch (Exception $e) {
	echo $e;
}

$i = 0;

$ignored_extensions = array('jpg', 'gif', 'png', 'pdf', 'swf', 'pot', 'txt', 'html', 'css', 'htm', 'zip');

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

echo "Comparing " .sizeof($md5sums). " remote files...";
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

    if (md5_file($wordpress . $file) != $md5sums[$file]) {
		echo "". md5_file($wordpress . $file) . " != ". $md5sums[$file] ."\n";
        
        $failed[] = array($file);
    }


}
echo "... complete!" . PHP_EOL;

if ($failed) {
    echo CLI::theme_table($failed, array("Filename"), "Failed files");
} else {
    echo "Wordpress install is pristine!" . PHP_EOL;
}

?>
