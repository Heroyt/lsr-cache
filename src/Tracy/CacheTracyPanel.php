<?php
/**
 * @author Tomáš Vojík <xvojik00@stud.fit.vutbr.cz>, <vojik@wboy.cz>
 */

namespace Lsr\Caching\Tracy;

use Exception;
use Lsr\Caching\Cache;
use Tracy\IBarPanel;

final readonly class CacheTracyPanel implements IBarPanel
{
    public function __construct(
        private Cache $cache
    ){}

    /**
     * @inheritDoc
     */
    public function getTab() : string {
        try {
            $calls = $this->cache->getCalls();
            $callsFormatted = sprintf(lang('%d call', '%d calls', $calls, 'debugPanel'), $calls);
        } catch (Exception) {
            $callsFormatted = lang('Invalid cache class', context: 'debugPanel');
        }
        return <<<HTML
        <span title="Caching">
            <svg
                    xmlns="http://www.w3.org/2000/svg" version="1.1"
                    width="512" height="512" x="0" y="0" viewBox="0 0 512 512" style="enable-background:new 0 0 512 512"
                    xml:space="preserve" class=""><g><g xmlns="http://www.w3.org/2000/svg"><path
                                d="m464.002 512h-416c-11.118 0-18.428-11.739-13.4-21.73l105-209c2.54-5.07 7.73-8.27 13.4-8.27h207c5.69 0 10.89 3.22 13.43 8.32l104 209c4.956 9.976-2.318 21.68-13.43 21.68z"
                                fill="#fed843" data-original="#fed843"></path><path
                                d="m464.002 512h-208v-239h104c5.69 0 10.89 3.22 13.43 8.32l104 209c4.956 9.976-2.318 21.68-13.43 21.68z"
                                fill="#fabe2c" data-original="#fabe2c"></path><path
                                d="m303.002 15v209c0 8.28-6.72 15-15 15h-64c-8.28 0-15-6.72-15-15v-209c0-8.28 6.72-15 15-15h64c8.28 0 15 6.72 15 15z"
                                fill="#ffe3c0" data-original="#ffe3c0" class=""></path><path
                                d="m288.002 239h-32v-239h32c8.28 0 15 6.72 15 15v209c0 8.28-6.72 15-15 15z" fill="#fcd0a2"
                                data-original="#fcd0a2" class=""></path><path
                                d="m399.002 232v48c0 12.68-10.32 23-23 23h-240c-12.68 0-23-10.32-23-23v-48c0-12.68 10.32-23 23-23h240c12.68 0 23 10.32 23 23z"
                                fill="#47568f" data-original="#47568f" class=""></path><path
                                d="m399.002 232v48c0 12.68-10.32 23-23 23h-120v-94h120c12.68 0 23 10.32 23 23z" fill="#2a3c75"
                                data-original="#2a3c75" class=""></path><path
                                d="m197.712 374.09-61.29 137.91h-32.84l66.71-150.09c3.37-7.57 12.23-10.98 19.8-7.62 7.57 3.37 10.98 12.23 7.62 19.8z"
                                fill="#fabe2c" data-original="#fabe2c"></path><path
                                d="m407.752 512h-32.78l-60.7-137.96c-3.34-7.58.11-16.43 7.69-19.77s16.43.11 19.77 7.69z"
                                fill="#ff9100" data-original="#ff9100"></path><path
                                d="m271.002 368v144h-30v-144c0-8.28 6.72-15 15-15s15 6.72 15 15z" fill="#fabe2c"
                                data-original="#fabe2c"></path><path d="m271.002 368v144h-15v-159c8.28 0 15 6.72 15 15z"
                                                                     fill="#ff9100"
                                                                     data-original="#ff9100"></path></g></g>
            </svg>
            <span class="tracy-label">$callsFormatted</span>
        </span>
        HTML;
    }

    /**
     * @inheritDoc
     */
    public function getPanel() : string {
        try {
            $hits = $this->cache::$hit;
            $miss = $this->cache::$miss;
            $loadedKeys = $this->cache::$loadedKeys;
            arsort($loadedKeys);
        } catch (Exception) {
            $hits = 0;
            $miss = 0;
            $loadedKeys = [];
        }
        $panel = <<<HTML
        <h1>Caching</h1>
        <div class="tracy-inner">
            <div class="tracy-inner-container">
                <div class="p-3 my-2 rounded border">
                    <p><strong>Cache hits:</strong> $hits</p>
                    <p><strong>Cache miss:</strong> $miss</p>
                </div>
            </div>
            <div class="tracy-inner-container">
                <div class="p-3 my-2 rounded border">
                    <h5>Cache keys</h5>
                    <ul class="list-group">
        HTML;
        foreach ($loadedKeys as $key => [$count, $miss]) {
            $panel .= '<li class="list-group-item"><strong>'.$count.'&times;</strong> '.$key.($miss > 0 ?
                    '- '.$miss.'&times; miss' : '').'</li>';
        }
        $panel .= <<<HTML
                    </ul>
                </div>
            </div>
        </div>
        HTML;
        return $panel;
    }
}