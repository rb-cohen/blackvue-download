<?php

require(__DIR__ . '/../vendor/autoload.php');

$cliConfig = array(
    'help' => array(
        'alias' => 'h',
        'help' => 'Show help about all options',
    ),
    'ip' => array(
        'alias' => 'i',
        'default' => false,
        'help' => 'The IP address of the BlackVue camera on your local network (e.g. 192.168.0.5)',
        'filter' => 'string',
    ),
    'directory' => array(
        'alias' => 'd',
        'default' => false,
        'help' => 'The path to store the video files in',
        'filter' => 'string',
    ),
    'ignore-existing' => array(
        'default' => false,
        'help' => 'Download the video file, even if it already exists in the directory',
        'filter' => 'boolean',
    ),
    'connect-timeout' => array(
        'default' => 5,
        'help' => 'Default connection timeout in seconds',
        'filter' => 'integer'
    ),
    'download-timeout' => array(
        'default' => 300,
        'help' => 'Video download timeout in seconds',
        'filter' => 'integer'
    )
);

use splitbrain\phpcli\CLI;
use splitbrain\phpcli\Options;
use splitbrain\phpcli\Exception as CliException;

class DownloadCLI extends CLI
{
    // register options and arguments
    protected function setup(Options $options)
    {
        $options->setHelp('Download video files from BlackVue on local network');
        $options->registerOption('ip', 'IP address of device', 'i', true);
        $options->registerOption('directory', 'Directory to download videos to', 'd', true);
        $options->registerOption('ignore-existing', 'Download video even if it already exists');
    }

    // implement your code
    protected function main(Options $options)
    {
        $ip = $options->getOpt('ip');
        $directory = $options->getOpt('directory');
        $ignoreExisting = $options->getOpt('ignore-existing');
        $connectTimeout = (int) $options->getOpt('connect-timeout');
        $downloadTimeout = (int) $options->getOpt('download-timeout');

        if(empty($ip)){
            throw new CliException('--ip (-i) is a required parameter');
        }

        if(empty($directory)){
            throw new CliException('--directory (-d) is a required parameter');
        }

        if(!file_exists($directory) || !is_dir($directory)){
            throw new CliException('--directory (-d) must be an existing directory');
        }

        if(!is_writable($directory)){
            throw new CliException('--directory (-d) is not writeable');
        }

        $client = new \GuzzleHttp\Client([
            // Base URI is used with relative requests
            'base_uri' => "http://$ip",
            // You can set any number of default request options.
            'timeout'  => $connectTimeout,
        ]);

        try {
            $listingResponse = $client->request('GET', 'blackvue_vod.cgi');
        }catch(\GuzzleHttp\Exception\ConnectException $e){
            throw new CliException('Could not connect to dashcam, is it powered on and connected?', 2, $e);
        }

        if($listingResponse->getStatusCode() != 200){
            throw new CliException('Could not get video list from dashcam', 2);
        }

        $listRaw = $listingResponse->getBody()->getContents();
        $list = explode("\n", trim($listRaw));
        $listVersion = array_shift($list);
        $numberOfFiles = count($list);

        if(strstr($listVersion, 'v:1.00') === false){
            throw new CliException("Invalid list version $listVersion, expecting 1.00");
        }

        $this->info('List version is {version}', array('version' => $listVersion));
        $this->info('Discovered {numberOfFiles} videos on device', array('numberOfFiles' => $numberOfFiles));

        // get file info
        //
        // returns array(
        //    'path' => '/Record/20180703_183000_NF.mp4',
        //    'filename' => '20180703_183000_NF.mp4',
        //    'date' => \DateTime,
        //    's' => '1000000',
        // );
        $files = array_map(function($string){
            $parts = explode(',', $string);

            $path = preg_replace('/^n:/', '', $parts[0]);
            $filename = basename($path);
            if(preg_match('/([0-9]{8})_([0-9]{6})/', $filename, $dateParts)) {
                $date = new \DateTime($dateParts[1] . 'T' . $dateParts[2]);
            }else{
                throw new \CliException('Could not parse date from filename {filename}', array('filename' => $filename));
            }

            return array(
                'path' => $path,
                'filename' => $filename,
                'date' => $date,
                's' => preg_replace('/^s:/', '', $parts[1]), // not sure what 's' is?
            );
        }, $list);

        // grab the files
        foreach($files as $file){
            $subDirectory = $file['date']->format('Y-m-d');
            $fullStoreDirectory = $directory . DIRECTORY_SEPARATOR . $subDirectory;
            $fullStorePath = $fullStoreDirectory . DIRECTORY_SEPARATOR . $file['filename'];
            $tmpStorePath = $fullStoreDirectory . DIRECTORY_SEPARATOR . $file['filename'] . '.part';

            // make the subfolder of the video day
            if(!file_exists($fullStoreDirectory)){
                $result = mkdir($fullStoreDirectory);
                if($result) {
                    $this->info('Created subdirectory {subdirectory}', array('subdirectory' => $subDirectory));
                }else{
                    throw new CliException('Could not create subdirectory {subdirectory} ', array('subdirectory' => $subDirectory));
                }
            }

            if($ignoreExisting === false && file_exists($fullStorePath)){
                $this->info('Skipping existing {path}', $file);
            }else{
                $this->info('Downloading {path} ...', $file);
                $downloadResponse = $client->request('GET', $file['path'], array(
                    'sink' => $tmpStorePath,
                    'timeout' => $downloadTimeout
                ));

                if($downloadResponse->getStatusCode() === 200){
                    rename($tmpStorePath, $fullStorePath);
                    $this->success('Successfully downloaded {path}', $file);
                }else{
                    $this->error('Failed to download {path}', $file['path']);
                    if(file_exists($tmpStorePath)) {
                        unlink($tmpStorePath);
                    }
                }
            }
        }

        $this->success('Done!');
    }
}
// execute it
$cli = new DownloadCLI();
$cli->run();


