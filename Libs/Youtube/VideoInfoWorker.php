<?php

namespace Libs\Youtube;

use React\EventLoop\Factory;
use React\HttpClient\Client;

/**
 * @author Gadel Raymanov <raymanovg@gmail.com>
 */
class VideoInfoWorker
{
    /**
     * @var null|\React\EventLoop\LoopInterface
     */
    protected $loop;

    protected $videoIds = [];
    protected $videosInfo = [];

    /**
     * @var callable
     */
    protected $onSuccess;
    protected $onFail;

    protected $videoInfoUrlPattern = 'https://www.youtube.com/get_video_info?video_id=%s';

    /**
     * VideoInfo constructor.
     * @param null $loop
     */
    public function __construct($loop = null)
    {
        if ($loop === null) {
            $loop = Factory::create();
        }

        $this->loop = $loop;
        $this->client = new Client($loop);
    }

    /**
     * @param string $videoId
     */
    public function setVideoId(string $videoId)
    {
        $this->videoIds[] = $videoId;
    }

    /**
     * @param callable $onSuccess
     */
    public function setOnSuccess(callable $onSuccess)
    {
        $this->onSuccess = $onSuccess;
    }

    /**
     * @param callable $onFail
     */
    public function setOnFail(callable $onFail)
    {
        $this->onFail = $onFail;
    }

    /**
     * Start handling video info
     */
    public function retrieve()
    {
        foreach ($this->videoIds as $videoId) {
            $request = $this->client->request('GET', sprintf($this->videoInfoUrlPattern, $videoId));
            $request->on('response', function (\React\HttpClient\Response $response) use ($videoId) {
                $response->on('data', function ($data) use ($videoId) {
                    $this->handleResponseBody($videoId, $data);
                });
                $response->on('close', function () use ($videoId) {
                    $this->onCloseResponse($videoId);
                });
                $response->on('error', function ($error) {
                    ($this->onFail)($error);
                });
            });

            $request->end();
        }
    }

    /**
     * @param string $videoId
     * @param string $data
     */
    protected function handleResponseBody(string $videoId, string $data)
    {
        if (isset($this->videosInfo[$videoId])) {
            $this->videosInfo[$videoId] .= $data;
        } else {
            $this->videosInfo[$videoId] = $data;
        }
    }

    /**
     * @param string $videoId
     */
    protected function onCloseResponse(string $videoId)
    {
        if (empty($this->videosInfo[$videoId])) {
            ($this->onFail)(new YoutubeException('Cannot get video info [ ' . $videoId . ']'));

            return;
        }

        try {
            ($this->onSuccess)($this->parseVideoInfo($videoId, $this->videosInfo[$videoId]));
        } catch (YoutubeException $exception) {
            ($this->onFail)($exception);
        }
    }

    /**
     * @param string $videoId
     * @param string $videoInfoContent
     * @return array
     * @throws YoutubeException
     */
    protected function parseVideoInfo(string $videoId, string $videoInfoContent) : array
    {
        parse_str($videoInfoContent, $fileInfo);
        $info = json_decode(json_encode($fileInfo), true);
        if (!isset($info['url_encoded_fmt_stream_map'], $info['title'])) {
            throw new YoutubeException('Cannot get video info [ ' . $videoId . ' ]');
        }

        $videoInfo = [
            'title' => preg_replace('/[\s+|\/]/', '_', $info['title']),
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