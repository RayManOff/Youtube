<?php


namespace Libs\Youtube;

use React\EventLoop\LoopInterface;
use React\HttpClient\Client;
use React\Promise\Deferred;

/**
 * @author Gadel Raymanov <raymanovg@gmail.com>
 */
class Info
{
    protected $loop;
    protected $client;
    protected $stream;
    protected $videoInfo;

    protected $videoInfoUrlPattern = 'https://www.youtube.com/get_video_info?video_id=%s';

    public function __construct($loop)
    {
        $this->loop = $loop;
        $this->client = new Client($this->loop);
    }

    /**
     * @param string $videoId
     * @return \React\Promise\Promise|\React\Promise\PromiseInterface
     */
    public function retrieve(string $videoId)
    {
        $deferred = new Deferred();
        $requestPromise = $this->request($videoId);
        $requestPromise->done(
            function ($rawVideoInfo) use ($deferred) {
                try {
                    $deferred->resolve($this->parseVideoInfo($rawVideoInfo));
                } catch (\Exception $e) {
                    $deferred->reject($e);
                }
            },
            function (YoutubeException $error) use ($deferred) {
                $deferred->reject($error);
            }
        );

        return $deferred->promise();
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

    protected function request(string $videoId)
    {
        $deferred = new Deferred();
        $request = $this->client->request('GET', sprintf($this->videoInfoUrlPattern, $videoId));
        $request->on('response', function (\React\HttpClient\Response $response) use ($deferred) {
            $body = '';
            $response->on('data', function ($data) use (&$body, $deferred) {
                $body .= $data;
            });
            $response->on('close', function () use (&$body, $deferred) {
                if (empty($body)) {
                    $deferred->reject(new YoutubeException('Cannot get video info'));
                } else {
                    $deferred->resolve($body);
                }
            });
            $response->on('error', function ($error) use ($deferred) {
                $deferred->reject($error);
            });
        });

        $request->end();

        return $deferred->promise();
    }
}