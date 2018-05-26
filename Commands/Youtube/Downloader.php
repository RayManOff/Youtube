<?php

namespace Commands\Youtube;

use Libs\Youtube\VideoDownloader;
use Libs\Youtube\VideoInfoWorker;
use Libs\Youtube\YoutubeException;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
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
    protected $highQuality = false;

    protected $videoInfoUrlPattern = 'https://www.youtube.com/get_video_info?video_id=%s';
    protected $path;

    protected function configure()
    {
        $this->setName('youtube:downloader');
        $this->setDescription('Downloader video form youtube');
        $this->addOption('video_ids', null, InputOption::VALUE_REQUIRED);
        $this->addOption('ids_path', null, InputOption::VALUE_REQUIRED);
        $this->addOption('path', null, InputOption::VALUE_REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->path = $input->getOption('path');
        $videoIds = $input->getOption('video_ids');
        $idsPath = $input->getOption('ids_path');

        if ($idsPath !== null) {
            $videoIds = $this->readIds($idsPath);
        }
        if ($videoIds === null) {
            error_log('Video ids are required');

            return;
        }

        if (!is_array($videoIds)) {
            if (strpos($videoIds, ',') === false) {
                $videoIds = (array) $videoIds;
            } else {
                $videoIds = explode(',', $videoIds);
            }
        }

        $this->loop = Factory::create();
        $videoInfo = new VideoInfoWorker($this->loop);
        $videoInfo->setOnSuccess([$this, 'download']);

        $videoInfo->setOnFail(function (YoutubeException $exception) {
            error_log($exception->getMessage());
        });

        foreach ($videoIds as $index => $videoId) {
            $videoInfo->setVideoId($videoId);
        }

        $videoInfo->retrieve();

        $this->loop->run();
    }

    public function download(array $videoInfo)
    {
        static $position = 0;
        ++$position;

        $downloader = new VideoDownloader($this->loop);
        if ($this->path !== null) {
            $downloader->setPath($this->path);
        }

        $videoInfo = $this->getHighQuality($videoInfo);
        $fileName = $videoInfo['title'];

        echo "\n";
        $downloader->setProgressBar(function ($size) use ($fileName, $position) {
            $currentSize = 0;
            $progress = new \React\Stream\ThroughStream();
            $progress->on('data', function($data) use ($size, &$currentSize, $fileName, $position){
                $currentSize += strlen($data);
                echo str_repeat("\033[1A", $position),
                "{$fileName}: ", number_format($currentSize / $size * 100), "%",
                str_repeat("\n", $position);
            });

            return $progress;
        });

        $downloader->download($videoInfo);
    }

    protected function readIds($filePath)
    {
        if (!file_exists($filePath)) {
            error_log("Cannot find {$filePath}");

            return null;
        }

        return explode(PHP_EOL, file_get_contents($filePath));
    }

    protected function getHighQuality(array $videoInfo) : array
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
}