<?php

namespace Commands\Youtube;

use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\HttpClient\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Gadel Raymanov <raymanovg@gmail.com>
 */
class Downloader
    extends Command
{
    /**
     * @var LoopInterface
     */
    protected $loop;
    /**
     * @var Client
     */
    protected $client;
    protected $requests = [];

    protected $videoInfoUrlPattern = 'https://www.youtube.com/get_video_info?video_id=%s';
    protected $videoFilePattern = '/home/gadel/Videos/%s.mp4';

    protected function configure()
    {
        $this->setName('youtube:downloader');
        $this->setDescription('Downloader video form youtube');
        $this->addOption('video_ids', null, InputOption::VALUE_REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $videoIds = $input->getOption('video_ids');
        if (strpos($videoIds, ',') === false) {
            $videoIds = (array) $videoIds;
        } else {
            $videoIds = explode(',', $videoIds);
        }

        $this->loop = Factory::create();
        $this->client = new Client($this->loop);

        $this->download($videoIds);
    }

    public function download(array $videoIds)
    {
        foreach ($videoIds as $index => $videoId) {
            $this->init($videoId, $index + 1);
        }

        $this->loop->run();
    }

    protected function init($videoId, $position)
    {
        $url = sprintf($this->videoInfoUrlPattern, $videoId);
        $videoInfo = new \React\Stream\ThroughStream();
        $request = $this->client->request('GET', $url /*, [CURLOPT_COOKIEFILE => '', CURLOPT_COOKIEJAR => '']*/);
        $request->on('response', function (\React\HttpClient\Response $response) use ($videoInfo) {
            $response->on('end', function () use ($videoInfo) {
                $videoInfo->emit('done');
            });

            $response->pipe($videoInfo);
        });

        $infoContent = '';
        $videoInfo->on('data', function ($data) use (&$infoContent) {
            $infoContent .= $data;
        });

        $videoInfo->on('done', function () use (&$infoContent, $position) {
            $videoInfo = $this->parseVideoInfo($infoContent);
            if ($videoInfo !== false) {
                $fileName = sprintf($this->videoFilePattern, $videoInfo['title']);
                $url = $videoInfo['types'][0]['url'];
                $videoFile = new \React\Stream\WritableResourceStream(fopen($fileName, 'w'), $this->loop);
                $request = $this->client->request('GET', $url);
                $request->on('response', function (\React\HttpClient\Response $response) use ($videoFile, $videoInfo, $position) {
                    $size = $response->getHeaders()['Content-Length'];
                    $progress = $this->makeProgressStream($size, $videoInfo['title'], $position);
                    $response->pipe($progress)->pipe($videoFile);
                });

                $request->end();
            }
        });

        $request->end();
    }

    /**
     * @param int $size
     * @param string $fileName
     * @param int $position
     * @return \React\Stream\ThroughStream
     */
    protected function makeProgressStream($size, $fileName, $position)
    {
        $currentSize = 0;

        $progress = new \React\Stream\ThroughStream();
        echo "\n";
        $progress->on('data', function($data) use ($size, &$currentSize, $fileName, $position){
            $currentSize += strlen($data);
            echo str_repeat("\033[1A", $position),
            "$fileName: ", number_format($currentSize / $size * 100), '%',
            str_repeat("\n", $position);
        });

        return $progress;
    }

    protected function parseVideoInfo($videoInfoFileContent)
    {
        parse_str($videoInfoFileContent, $fileInfo);
        $info = json_decode(json_encode($fileInfo), true);
        if (!isset($info['url_encoded_fmt_stream_map'], $info['title'])) {
            return false;
        }

        $videoInfo = [
            'title' => $info['title'],
            'types' => []
        ];

        foreach (explode(',', $info['url_encoded_fmt_stream_map']) as $typeInfo) {
            parse_str($typeInfo, $info);
            if (!isset($info['quality']) || $info['quality'] !== 'hd720') {
                continue;
            }

            $videoInfo['types'][] = [
                'quality' => $info['quality'],
                'type' => explode(';', $info['type'])[0],
                'url' => $info['url']
            ];
        }

        return $videoInfo;
    }
}