<?php

namespace Commands\Youtube;

use Clue\React\Stdio\Stdio;
use Libs\Youtube\Info;
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
    protected $highQuality = false;

    protected $videoInfoUrlPattern = 'https://www.youtube.com/get_video_info?video_id=%s';
    protected $videoFilePattern = __DIR__ . '/Videos/%s';

    protected function configure()
    {
        $this->setName('youtube:downloader');
        $this->setDescription('Downloader video form youtube');
        $this->addOption('video_ids', null, InputOption::VALUE_REQUIRED);
        $this->addOption('high_quality', null, InputOption::VALUE_REQUIRED);
        $this->addOption('path', null, InputOption::VALUE_REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->highQuality = (bool) $input->getOption('high_quality');
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
        $info = new Info($this->loop);

        foreach ($videoIds as $videoId) {
            $videoInfoPromise = $info->retrieve($videoId);
            $videoInfoPromise->done(
                function ($data) {
                    var_dump($data);die;
                },
                function ($data) {
                    var_dump($data->getMessage());
//                var_dump($data->getMessage());die;
                }
            );
        }

        $this->loop->run();
//        $this->handle($videoIds);
    }

    public function handle(array $videoIds)
    {
        foreach ($videoIds as $index => $videoId) {
            $this->init($videoId, $index + 1);
        }

        foreach ($this->initRequests as $initRequest) {
            $initRequest->end();
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
                foreach ($this->downloadRequests as $downloadRequest) {
                    $downloadRequest->end();
                }
                $videoInfo->close();
            });

            $response->pipe($videoInfo);
        });

        $infoContent = '';
        $videoInfo->on('data', function ($data) use (&$infoContent) {
            $infoContent .= $data;
        });

        $videoInfo->on('close', function () use (&$infoContent, $videoInfo, $position) {
            $videoInfo = $this->parseVideoInfo($infoContent);
            if ($this->highQuality) {
                $this->downloadRequests[] = $this->buildDownloadRequest($this->getHighQualityVideo($videoInfo), $position);

                return;
            }

            /**
             * TODO videos is not downloaded parallel
             */
            $stdio = new Stdio($this->loop);
            $message = "Choose format for {$videoInfo['title']} : \n";
            foreach ($videoInfo['types'] as $index => $type) {
                $index++;
                $message .= "{$index}) ";
                list(, $format) = explode('/', $type['type']);
                $message .= "{$type['quality']} {$format} \n";
            }

            $stdio->getReadline()->setPrompt($message);
            $stdio->getReadline()->setPrompt('');
            $stdio->on('data', function ($line) use ($stdio, $videoInfo, $position) {
                $index = (int) trim($line);

                if (!isset($videoInfo['types'][--$index])) {
                    $stdio->write("\n Unexpected format. Please choose again... \n");

                    return;
                }

                list(, $format) = explode('/', $videoInfo['types'][$index]['type']);

                $videoInfoForDownload = [
                    'title' => $videoInfo['title'],
                    'url' => $videoInfo['types'][$index]['url'],
                    'format' => $format
                ];

               $this->downloadRequests[] = $this->buildDownloadRequest($videoInfoForDownload, $position);
            });
        });


        $this->initRequests[] = $request;
    }

    protected function getHighQualityVideo(array $videoInfo) : array
    {
        static $qualityTypes = [
            'hd720',
            'medium',
            'small'
        ];

        $highQualityVideo = null;
        foreach ($qualityTypes as $quality) {
            foreach ($videoInfo['types'] as $type) {
                if ($type['quality'] === $quality) {
                    $highQualityVideo = [
                        'title' => $videoInfo['title'],
                        'url' => $type['url'],
                        'format' => explode('/', $type['type'])[1]
                    ];
                    break 2;
                }
            }
        }

        return $highQualityVideo;
    }

    protected function buildDownloadRequest(array $videoInfo, int $videoCount)
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

        return $request;
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
        echo "\n";
        $progress = new \React\Stream\ThroughStream();
        $progress->on('data', function($data) use ($size, &$currentSize, $fileName, $position) {
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