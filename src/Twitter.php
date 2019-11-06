<?php

namespace Atymic\Twitter;

use Atymic\Twitter\Traits\AccountTrait;
use Atymic\Twitter\Traits\BlockTrait;
use Atymic\Twitter\Traits\DirectMessageTrait;
use Atymic\Twitter\Traits\FavoriteTrait;
use Atymic\Twitter\Traits\FriendshipTrait;
use Atymic\Twitter\Traits\GeoTrait;
use Atymic\Twitter\Traits\HelpTrait;
use Atymic\Twitter\Traits\ListTrait;
use Atymic\Twitter\Traits\MediaTrait;
use Atymic\Twitter\Traits\SearchTrait;
use Atymic\Twitter\Traits\StatusTrait;
use Atymic\Twitter\Traits\TrendTrait;
use Atymic\Twitter\Traits\UserTrait;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Response;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RunTimeException;

class Twitter
{
    const VERSION = '3.x-dev';

    use AccountTrait,
        BlockTrait,
        DirectMessageTrait,
        FavoriteTrait,
        FriendshipTrait,
        GeoTrait,
        HelpTrait,
        ListTrait,
        MediaTrait,
        SearchTrait,
        StatusTrait,
        TrendTrait,
        UserTrait;


    /** @var Configuration */
    protected $config;
    /** @var Client */
    protected $httpClient;
    /** @var LoggerInterface|null */
    protected $logger;

    /** @var bool */
    protected $debug;

    protected $error;

    public function __construct(Configuration $config, ?Client $httpClient = null, ?LoggerInterface $logger = null)
    {
        if ($httpClient === null) {
            $client = new Client();
        }

        $this->debug = $config->isDebugMode();

        // Todo session abstraction

        $this->config = $config;
        $this->httpClient = $httpClient;
    }

    public function reconfigure($config)
    {
        // TODO implement
    }

    public function log(string $message, array $context = [], string $logLevel = LogLevel::DEBUG): void
    {
        if ($this->logger === null) {
            return;
        }

        if (!$this->debug && $logLevel = LogLevel::DEBUG) {
            return;
        }

        $this->logger->log($logLevel, $message, $context);
    }

    /**
     * Get a request_token from Twitter.
     *
     * @param string $oauth_callback [Optional] The callback provided for Twitter's API. The user will be redirected
     *                               there after authorizing your app on Twitter.
     *
     * @return array|bool a key/value array containing oauth_token and oauth_token_secret in case of success
     */
    public function getRequestToken($oauth_callback = null)
    {
        $parameters = [];

        if (!empty($oauth_callback)) {
            $parameters['oauth_callback'] = $oauth_callback;
        }

        parent::request('GET', parent::url($this->tconfig['REQUEST_TOKEN_URL'], ''), $parameters);

        $response = $this->response;

        if (isset($response['code']) && $response['code'] == 200 && !empty($response)) {
            $get_parameters = $response['response'];
            $token = [];
            parse_str($get_parameters, $token);
        }

        // Return the token if it was properly retrieved
        if (isset($token['oauth_token'], $token['oauth_token_secret'])) {
            return $token;
        } else {
            throw new RunTimeException($response['response'], $response['code']);
        }
    }

    /**
     * Get an access token for a logged in user.
     *
     * @return array|bool key/value array containing the token in case of success
     */
    public function getAccessToken($oauth_verifier = null)
    {
        $parameters = [];

        if (!empty($oauth_verifier)) {
            $parameters['oauth_verifier'] = $oauth_verifier;
        }

        parent::request('GET', parent::url($this->tconfig['ACCESS_TOKEN_URL'], ''), $parameters);

        $response = $this->response;

        if (isset($response['code']) && $response['code'] == 200 && !empty($response)) {
            $get_parameters = $response['response'];
            $token = [];
            parse_str($get_parameters, $token);

            // Reconfigure the tmhOAuth class with the new tokens
            $this->reconfig([
                'token' => $token['oauth_token'],
                'secret' => $token['oauth_token_secret'],
            ]);

            return $token;
        }

        throw new RunTimeException($response['response'], $response['code']);
    }

    /**
     * Get the authorize URL.
     *
     * @returns string
     */
    public function getAuthorizeURL($token, $sign_in_with_twitter = true, $force_login = false)
    {
        if (is_array($token)) {
            $token = $token['oauth_token'];
        }

        if ($force_login) {
            return $this->tconfig['AUTHENTICATE_URL'] . "?oauth_token={$token}&force_login=true";
        } elseif (empty($sign_in_with_twitter)) {
            return $this->tconfig['AUTHORIZE_URL'] . "?oauth_token={$token}";
        } else {
            return $this->tconfig['AUTHENTICATE_URL'] . "?oauth_token={$token}";
        }
    }

    public function buildUrl(string $host, string $version, string $name, string $extension): string
    {
        return sprintf('https://%s/%s/%s.%s', $host, $version, $name, $extension);
    }

    public function query(
        string $name,
        string $requestMethod = 'GET',
        array $parameters = [],
        bool $multipart = false,
        string $extension = 'json'
    ) {
        $host = $multipart ? $this->config->getApiUrl() : $this->config->getUploadUrl();
        $url = $this->buildUrl($host, $this->config->getApiVersion(), $name, $extension);
        $format = 'array'; // todo const

        if (isset($parameters['format'])) {
            $format = $parameters['format'];
            unset($parameters['format']);
        }

        $this->log('Making Request', [
            'method' => $requestMethod,
            'query' => $name,
            'url' => $name,
            'params' => http_build_query($parameters),
            'multipart' => $multipart,
            'format' => $format,
        ]);


        $requestOptions = [];

        if ($requestMethod === 'GET') {
            $requestOptions['query'] = $parameters;
        }

        if ($requestMethod === 'POST') {
            $requestOptions['form_params'] = $parameters;
        }

        try {
            $response = $this->httpClient->request($requestMethod, $url, $requestOptions);
        } catch (ClientException $exception) {
            // todo handle this
            throw $exception;
        } catch (ServerException $exception) {
            // todo handle this
            throw $exception;
        } catch (RequestException $exception) {
            // todo handle this
            throw $exception;
        }

        return $this->getResponseAs($response, $format);
    }

    public function getResponseAs(Response $response, string $format)
    {
        $body = (string) $response->getBody();

        // todo const these
        switch ($format) {
            case 'object':
                return $this->jsonDecode($body, false);
            case 'json':
                return $body;
            default:
            case 'array':
                return $this->jsonDecode($body, true);
        }
    }

    public function get($name, $parameters = [], $multipart = false, $extension = 'json')
    {
        return $this->query($name, 'GET', $parameters, $multipart, $extension);
    }

    public function post($name, $parameters = [], $multipart = false)
    {
        return $this->query($name, 'POST', $parameters, $multipart);
    }

    public function linkify($tweet)
    {
        if (is_object($tweet)) {
            $type = 'object';
            $tweet = $this->jsonDecode(json_encode($tweet), true);
        } elseif (is_array($tweet)) {
            $type = 'array';
        } else {
            $type = 'text';
            $text = ' ' . $tweet;
        }

        $patterns = [];
        $patterns['url'] = '(?xi)\b((?:https?://|www\d{0,3}[.]|[a-z0-9.\-]+[.][a-z]{2,4}/)(?:[^\s()<>]+|\(([^\s()<>]+|(\([^\s()<>]+\)))*\))+(?:\(([^\s()<>]+|(\([^\s()<>]+\)))*\)|[^\s`!()\[\]{};:\'".,<>?«»“”‘’]))';
        $patterns['mailto'] = '([_a-z0-9-]+(\.[_a-z0-9-]+)*@[a-z0-9-]+(\.[a-z0-9-]+)*(\.[a-z]{2,3}))';
        $patterns['user'] = ' +@([a-z0-9_]*)?';
        $patterns['hashtag'] = '(?:(?<=\s)|^)#(\w*[\p{L}\-\d\p{Cyrillic}\d]+\w*)';
        $patterns['long_url'] = '>(([[:alnum:]]+:\/\/)|www\.)?([^[:space:]]{12,22})([^[:space:]]*)([^[:space:]]{12,22})([[:alnum:]#?\/&=])<';

        if ($type == 'text') {
            // URL
            $pattern = '(?xi)\b((?:https?://|www\d{0,3}[.]|[a-z0-9.\-]+[.][a-z]{2,4}/)(?:[^\s()<>]+|\(([^\s()<>]+|(\([^\s()<>]+\)))*\))+(?:\(([^\s()<>]+|(\([^\s()<>]+\)))*\)|[^\s`!()\[\]{};:\'".,<>?«»“”‘’]))';
            $text = preg_replace_callback('#' . $patterns['url'] . '#i', function ($matches) {
                $input = $matches[0];
                $url = preg_match('!^https?://!i', $input) ? $input : "http://$input";

                return '<a href="' . $url . '" target="_blank" rel="nofollow">' . "$input</a>";
            }, $text);
        } else {
            $text = $tweet['text'];
            $entities = $tweet['entities'];

            $search = [];
            $replace = [];

            if (array_key_exists('media', $entities)) {
                foreach ($entities['media'] as $media) {
                    $search[] = $media['url'];
                    $replace[] = '<a href="' . $media['media_url_https'] . '" target="_blank">' . $media['display_url'] . '</a>';
                }
            }

            if (array_key_exists('urls', $entities)) {
                foreach ($entities['urls'] as $url) {
                    $search[] = $url['url'];
                    $replace[] = '<a href="' . $url['expanded_url'] . '" target="_blank" rel="nofollow">' . $url['display_url'] . '</a>';
                }
            }

            $text = str_replace($search, $replace, $text);
        }

        // Mailto
        $text = preg_replace('/' . $patterns['mailto'] . '/i', '<a href="mailto:\\1">\\1</a>', $text);

        // User
        $text = preg_replace('/' . $patterns['user'] . '/i', ' <a href="https://twitter.com/\\1" target="_blank">@\\1</a>', $text);

        // Hashtag
        $text = preg_replace('/' . $patterns['hashtag'] . '/ui', '<a href="https://twitter.com/search?q=%23\\1" target="_blank">#\\1</a>', $text);

        // Long URL
        $text = preg_replace('/' . $patterns['long_url'] . '/', '>\\3...\\5\\6<', $text);

        // Remove multiple spaces
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    public function ago($timestamp)
    {
        if (is_numeric($timestamp) && (int) $timestamp == $timestamp) {
            $carbon = Carbon::createFromTimeStamp($timestamp);
        } else {
            $dt = new \DateTime($timestamp);
            $carbon = Carbon::instance($dt);
        }

        return $carbon->diffForHumans();
    }

    public function linkUser($user)
    {
        return 'https://twitter.com/' . (is_object($user) ? $user->screen_name : $user);
    }

    public function linkTweet($tweet)
    {
        return $this->linkUser($tweet->user) . '/status/' . $tweet->id_str;
    }

    public function linkRetweet($tweet)
    {
        return 'https://twitter.com/intent/retweet?tweet_id=' . $tweet->id_str;
    }

    public function linkAddTweetToFavorites($tweet)
    {
        return 'https://twitter.com/intent/favorite?tweet_id=' . $tweet->id_str;
    }

    public function linkReply($tweet)
    {
        return 'https://twitter.com/intent/tweet?in_reply_to=' . $tweet->id_str;
    }

    public function error()
    {
        return $this->error;
    }

    public function setError($code, $message)
    {
        $this->error = compact('code', 'message');

        return $this;
    }

    private function jsonDecode($json, $assoc = false)
    {
        if (version_compare(PHP_VERSION, '5.4.0', '>=') && !(defined('JSON_C_VERSION') && PHP_INT_SIZE > 4)) {
            return json_decode($json, $assoc, 512, JSON_BIGINT_AS_STRING);
        } else {
            return json_decode($json, $assoc);
        }
    }
}
