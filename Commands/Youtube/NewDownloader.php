<?php
namespace Commands\Youtube;

use Clue\React\Stdio\Stdio;
use Libs\Youtube\YoutubeException;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\HttpClient\Client;
use React\Stream\ThroughStream;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Gadel Raymanov <raymanovg@gmail.com>
 */
class NewDownloader
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

    protected $videoFilePattern = '/tmp/%s';
    protected $highQuality = false;

    protected function configure()
    {
        $this->setName('youtube:new_downloader');
        $this->setDescription('Downloader video form youtube');
        $this->addOption('video_id', null, InputOption::VALUE_REQUIRED);
        $this->addOption('high_quality', null, InputOption::VALUE_REQUIRED);
        $this->addOption('path', null, InputOption::VALUE_REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->highQuality = (bool) $input->getOption('high_quality');
        $this->loop = Factory::create();
        $this->client = new Client($this->loop);
        $videoInfoRequest = $this->requestVideoInfo($input->getOption('video_id'));
        $videoInfoStream = new \React\Stream\ThroughStream();
        $videoInfoRequest->on('response', function (\React\HttpClient\Response $response) use ($videoInfoStream) {
            $response->pipe($videoInfoStream);
        });

        $this->readVideoInfo($videoInfoStream);

        $videoInfoRequest->end();

        $this->loop->run();
    }

    public function readVideoInfo(ThroughStream $videoInfoStream)
    {
        $videoInfoContent = '';
        $videoInfoStream->on('data', function ($data) use (&$videoInfoContent) {
            $videoInfoContent .= $data;
        });

        $videoInfoStream->on('end', function () use (&$videoInfoContent) {
            $parsedVideoInfo = $this->parseVideoInfo($videoInfoContent);
            if ($this->highQuality) {
                $highQualityVideo = $this->getHighQualityVideo($parsedVideoInfo);
                $downloadRequest = $this->getDownloadRequest($highQualityVideo);
                $downloadRequest->end();
            } else {
                $this->chooseVideoAndDownload($parsedVideoInfo);
            }
        });
    }

    protected function getDownloadRequest($videoInfo)
    {
        $fileName =  $videoInfo['title'] . '.' . $videoInfo['format'];
        $filePath = sprintf($this->videoFilePattern, preg_replace('/\s+/', '_', $fileName));
        $videoFile = new \React\Stream\WritableResourceStream(fopen($filePath, 'w'), $this->loop);
        $request = $this->client->request('GET', $videoInfo['url']);
        $request->on('response', function (\React\HttpClient\Response $response) use ($videoFile, $videoInfo) {
            $size = $response->getHeaders()['Content-Length'];
            $progress = $this->makeProgressStream($size, $videoInfo['title']);
            $response->pipe($progress)->pipe($videoFile);
        });

        return $request;
    }

    protected function chooseVideoAndDownload($videoInfo)
    {
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
        $stdio->on('data', function ($line) use ($stdio, $videoInfo) {
            $index = (int) trim($line);

            if (!isset($videoInfo['types'][--$index])) {
                $stdio->write("\n Unexpected format. Please choose again... \n");

                return;
            }

            list(, $format) = explode('/', $videoInfo['types'][$index]['type']);

            $downloadRequest = $this->getDownloadRequest([
                'title' => $videoInfo['title'],
                'url' => $videoInfo['types'][$index]['url'],
                'format' => $format
            ]);

            $downloadRequest->on('close', function () use ($stdio) {
                echo "\n Done... \n";
                $stdio->end();
            });

            $downloadRequest->end();
        });
    }

    /**
     * @param int $size
     * @param string $fileName
     * @param int $position
     * @return \React\Stream\ThroughStream
     */
    protected function makeProgressStream($size, $fileName)
    {
        $currentSize = 0;
        echo "\n";
        $progress = new \React\Stream\ThroughStream();
        $progress->on('data', function($data) use ($size, &$currentSize, $fileName) {
            $currentSize += strlen($data);
            $percent = number_format($currentSize / $size * 100);
            echo "\033[1A {$fileName}: {$percent} % \n";
        });

        return $progress;
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

    protected function requestVideoInfo($videoId)
    {
        return $this->client->request('GET', "https://www.youtube.com/get_video_info?video_id={$videoId}");
    }
}