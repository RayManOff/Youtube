<?php

namespace Commands\Youtube;

use Clue\React\Stdio\Stdio;
use Libs\Youtube\YoutubeException;
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
    protected $videoFilePattern = __DIR__ . '/Videos/%s';

    protected function configure()
    {
        $this->setName('youtube:downloader');
        $this->setDescription('Downloader video form youtube');
        $this->addOption('video_ids', 'ids', InputOption::VALUE_REQUIRED);
        $this->addOption('path', null, InputOption::VALUE_REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $videoIds = $input->getOption('video_ids');
        if ($videoIds === null) {
            error_log('Video ids are required');

            return;
        }

        $path = $input->getOption('path');
        if ($path !== null) {
            if (strpos($path, '/', -1) === false) {
                $path = $path . '/';
            }
            $this->videoFilePattern = $path . '%s';
        }

        if (strpos($videoIds, ',') === false) {
            $videoIds = (array) $videoIds;
        } else {
            $videoIds = explode(',', $videoIds);
        }

        $this->loop = Factory::create();
        $this->client = new Client($this->loop);

        $this->handle($videoIds);
    }

    public function handle(array $videoIds)
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
        $request = $this->client->request('GET', $url);
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
            $videoType = new \React\Stream\ThroughStream();
            $videoType->on('data', function ($videoType) use ($position) {
                try {
                    $this->download($videoType, $position);
                } catch (\Exception $e) {
                    error_log($e->getMessage());
                }
            });

            try {
                $this->chooseVideoType($videoType, $this->parseVideoInfo($infoContent));
            } catch (\Exception $e) {
                $videoType->end();
            }
        });

        $request->end();
    }

    protected function download(array $videoInfo, int $videoCount)
    {
        $fileName =  $videoInfo['title'] . '.' . $videoInfo['format'];
        $filePath = sprintf($this->videoFilePattern, $fileName);
        $videoFile = new \React\Stream\WritableResourceStream(fopen($filePath, 'w'), $this->loop);
        $request = $this->client->request('GET', $videoInfo['url']);
        $request->on('response', function (\React\HttpClient\Response $response) use ($videoFile, $videoInfo, $videoCount) {
            $size = $response->getHeaders()['Content-Length'];
            $progress = $this->makeProgressStream($size, $videoInfo['title'], $videoCount);
            $response->pipe($progress)->pipe($videoFile);
        });

        $request->end();
    }

    protected function chooseVideoType($videoType, array $videoInfo)
    {
        $stdio = new Stdio($this->loop);
        $message = "Choose format for {$videoInfo['title']} : \n";
        foreach ($videoInfo['types'] as $index => $type) {
            $index++;
            $message .= ("{$index}) ");
            list(, $format) = explode('/', $type['type']);
            $message .= "{$type['quality']} {$format} \n";
        }

        $stdio->getReadline()->setPrompt($message);
        $stdio->on('data', function ($line) use ($stdio, $videoInfo, $videoType) {
            $index = (int) trim($line);
            if (!isset($videoInfo['types'][--$index])) {
                $stdio->write("Unexpected format. Please choose again... \n");

                return;
            }

            list(, $format) = explode('/', $videoInfo['types'][$index]['type']);

            $videoInfoForDownload = [
                'title' => $videoInfo['title'],
                'url' => $videoInfo['types'][$index]['url'],
                'format' => $format
            ];

            $videoType->emit('data', [$videoInfoForDownload]);
            $stdio->end();
        });
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

    /**
     * @param $videoInfoFileContent
     * @return array
     * @throws YoutubeException
     */
    protected function parseVideoInfo($videoInfoFileContent) : array
    {
        parse_str($videoInfoFileContent, $fileInfo);
        $info = json_decode(json_encode($fileInfo), true);
        if (!isset($info['url_encoded_fmt_stream_map'], $info['title'])) {
            throw new YoutubeException('Cannot get video info');
        }

        $videoInfo = [
            'title' => $info['title'],
            'types' => []
        ];

        foreach (explode(',', $info['url_encoded_fmt_stream_map']) as $typeInfo) {
            parse_str($typeInfo, $info);
            $videoInfo['types'][] = [
                'quality' => $info['quality'],
                'type' => explode(';', $info['type'])[0],
                'url' => $info['url']
            ];
        }

        return $videoInfo;
    }
}