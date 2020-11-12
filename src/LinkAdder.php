<?php

namespace Spatie\Crawler;

use GuzzleHttp\Psr7\Uri;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Psr\Http\Message\UriInterface;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;
use Symfony\Component\DomCrawler\Image;
use Symfony\Component\DomCrawler\Link;
use Tree\Node\Node;

class LinkAdder
{
    /** @var \Spatie\Crawler\Crawler */
    protected $crawler;

    public function __construct(Crawler $crawler)
    {
        $this->crawler = $crawler;
    }

    public function addFromHtml(string $html, UriInterface $foundOnUrl)
    {
        $allLinks = $this->extractLinksFromHtml($html, $foundOnUrl);

        collect($allLinks)
            ->reject(function ($url) {
                if (empty($url)) {
                    return true;
                }
                return false;
            })
            ->filter(function (UriInterface $url) {
                return $this->hasCrawlableScheme($url);
            })
            ->map(function (UriInterface $url) {
                return $this->normalizeUrl($url);
            })
            ->filter(function (UriInterface $url) use ($foundOnUrl) {
                if (! $node = $this->crawler->addToDepthTree($url, $foundOnUrl)) {
                    return false;
                }

                return $this->shouldCrawl($node);
            })
            ->filter(function (UriInterface $url) {
                return strpos($url->getPath(), '/tel:') === false;
            })
            ->each(function (UriInterface $url) use ($foundOnUrl) {
                if ($this->crawler->maximumCrawlCountReached()) {
                    return;
                }

                $crawlUrl = CrawlUrl::create($url, $foundOnUrl);

                $this->crawler->addToCrawlQueue($crawlUrl);
            });
    }

    /**
     * @param string $html
     * @param \Psr\Http\Message\UriInterface $foundOnUrl
     *
     * @return array
     */
    protected function extractLinksFromHtml(string $html, UriInterface $foundOnUrl)
    {
        /** Replace all " to ' in background image url */
        $html = str_replace('url("', 'url(\'', $html);

        $links = [];
        $domCrawler = new DomCrawler($html, $foundOnUrl);

        /**
         * Links from div background
         */
        $links = array_merge(
            $links,
            collect($domCrawler->filterXPath("//div[@style]")->extract(['style']))
                ->map(function ($style) {
                    try {
                        $backGroundImageUrl = new Uri(Str::between($style, 'url(\'', '\')'));
                        if (empty($backGroundImageUrl->getScheme())) {
                            $backGroundImageUrl->withScheme($this->crawler->getBaseUrl()->getScheme());
                        }
                        if (empty($backGroundImageUrl->getHost())) {
                            $backGroundImageUrl->withHost($this->crawler->getBaseUrl()->getHost());
                        }
                        return $backGroundImageUrl;
                    } catch (InvalidArgumentException $exception) {
                        return;
                    }
                })
                ->toArray()
        );

        /**
         * Links from img src
         */
        $links = array_merge(
            $links,
            collect($domCrawler->filterXpath('//img')->images())
                ->map(function (Image $image) {
                    try {
                        return new Uri($image->getUri());
                    } catch (InvalidArgumentException $exception) {
                        return;
                    }
                })
                ->toArray()
        );

        /**
         * Links from a and link node
         */
        $links = array_merge(
            $links,
            collect($domCrawler->filterXpath('//a | //link[@rel="next" or @rel="prev"]')->links())
                ->reject(function (Link $link) {
                    if ($this->isInvalidHrefNode($link)) {
                        return true;
                    }

                    if ($link->getNode()->getAttribute('rel') === 'nofollow') {
                        return true;
                    }

                    return false;
                })
                ->map(function (Link $link) {
                    try {
                        return new Uri($link->getUri());
                    } catch (InvalidArgumentException $exception) {
                        return;
                    }
                })
                ->filter()->toArray());
        return $links;
    }

    protected function hasCrawlableScheme(UriInterface $uri): bool
    {
        return in_array($uri->getScheme(), ['http', 'https']);
    }

    protected function normalizeUrl(UriInterface $url): UriInterface
    {
        return $url->withFragment('');
    }

    protected function shouldCrawl(Node $node): bool
    {
        if ($this->crawler->mustRespectRobots() && ! $this->crawler->getRobotsTxt()->allows($node->getValue(), $this->crawler->getUserAgent())) {
            return false;
        }

        $maximumDepth = $this->crawler->getMaximumDepth();

        if (is_null($maximumDepth)) {
            return true;
        }

        return $node->getDepth() <= $maximumDepth;
    }

    protected function isInvalidHrefNode(Link $link): bool
    {
        if ($link->getNode()->nodeName !== 'a') {
            return false;
        }

        if ($link->getNode()->nextSibling !== null) {
            return false;
        }

        if ($link->getNode()->childNodes->length !== 0) {
            return false;
        }

        return true;
    }
}
