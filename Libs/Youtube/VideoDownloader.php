<?php

namespace Libs\Youtube;

use React\HttpClient\Client;

/**
 * @author Gadel Raymanov <raymanovg@gmail.com>
 */
class VideoDownloader
{
    protected $loop;
    protected $client;
    protected $progressBar;
    protected $videoFile;

    protected $videoFilePattern = __DIR__ . '/Videos/%s';

    /**
     * VideoDownloader constructor.
     * @param $loop
     */
    public function __construct($loop)
    {
        $this->loop = $loop;
        $this->client = new Client($loop);
    }

    /**
     * @param string $path
     */
    public function setPath(string $path)
    {
        if (strpos($path, '/', -1) === false) {
            $path = $path . '/';
        }

        $this->videoFilePattern = $path . '%s';
    }

    /**
     * @param callable $progressBar
     */
    public function setProgressBar(callable $progressBar)
    {
        $this->progressBar = $progressBar;
    }

    /**
     * @param array $video
     */
    public function download(array $video)
    {
        $fileName =  $video['title'] . '.' . $video['format'];
        $filePath = sprintf($this->videoFilePattern, $fileName);
        $this->videoFile = new \React\Stream\WritableResourceStream(fopen($filePath, 'w'), $this->loop);
        $request = $this->client->request('GET', $video['url']);
        $request->on('response', function (\React\HttpClient\Response $response) use ($video) {
            $headers = $response->getHeaders();
            if ($this->progressBar !== null) {
                $progressStream = ($this->progressBar)($headers['Content-Length']);
                $response->pipe($progressStream)->pipe($this->videoFile);
            } else {
                $response->pipe($this->videoFile);
            }
        });

        $request->end();
    }
}