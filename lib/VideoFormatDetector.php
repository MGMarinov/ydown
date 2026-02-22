<?php
declare(strict_types=1);

final class VideoFormatDetector
{
    private const TIMEOUT_SEKUNDEN = 20;
    private bool $sslFallbackGenutzt = false;
    private YtDlpTool $ytDlpWerkzeug;

    public function __construct(?YtDlpTool $ytDlpWerkzeug = null)
    {
        $this->ytDlpWerkzeug = $ytDlpWerkzeug ?? new YtDlpTool();
    }

    public function wurdeSslFallbackGenutzt(): bool
    {
        return $this->sslFallbackGenutzt;
    }

    public function istYtDlpVerfuegbar(): bool
    {
        return $this->ytDlpWerkzeug->istVerfuegbar();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function ermittleOptionen(string $seitenUrl): array
    {
        $this->sslFallbackGenutzt = false;
        $bereinigteUrl = $this->validiereUndNormalisiereUrl($seitenUrl);

        if ($this->ytDlpWerkzeug->istYouTubeUrl($bereinigteUrl)) {
            if (!$this->ytDlpWerkzeug->istVerfuegbar()) {
                throw new RuntimeException('YouTube support requires yt-dlp. Install yt-dlp or set YTDLP_PATH.');
            }

            $optionen = $this->ytDlpWerkzeug->ermittleOptionen($bereinigteUrl);
            if ($optionen === []) {
                throw new RuntimeException('No formats could be determined for this YouTube video.');
            }

            $optionen = $this->sortiereOptionen($optionen);
            foreach ($optionen as $index => $option) {
                $optionen[$index]['id'] = $index;
            }

            return $optionen;
        }

        $seitenInhalt = $this->holeInhalt($bereinigteUrl);

        $optionen = [];

        foreach ($this->findeMp4Urls($seitenInhalt, $bereinigteUrl) as $mp4Url) {
            $optionen[] = $this->baueDirekteOption($mp4Url, 'direkt_mp4');
        }

        foreach ($this->findeM3u8Urls($seitenInhalt, $bereinigteUrl) as $m3u8Url) {
            $hlsOptionen = $this->ermittleHlsOptionen($m3u8Url);
            if ($hlsOptionen !== []) {
                $optionen = array_merge($optionen, $hlsOptionen);
            }
        }

        $optionen = $this->entferneDuplikate($optionen);
        $optionen = $this->sortiereOptionen($optionen);

        foreach ($optionen as $index => $option) {
            $optionen[$index]['id'] = $index;
        }

        return $optionen;
    }

    private function validiereUndNormalisiereUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new RuntimeException('Please enter a valid URL.');
        }

        $teile = parse_url($url);
        $schema = strtolower((string) ($teile['scheme'] ?? ''));
        if (!in_array($schema, ['http', 'https'], true)) {
            throw new RuntimeException('Only URLs with http or https are allowed.');
        }

        return $url;
    }

    private function holeInhalt(string $url): string
    {
        $ergebnis = $this->fuehreInhaltsRequestAus($url, true);
        if ($ergebnis['erfolg']) {
            /** @var string $inhalt */
            $inhalt = $ergebnis['inhalt'];
            return $inhalt;
        }

        $fehler = (string) $ergebnis['fehler'];
        $errno = (int) $ergebnis['errno'];
        if ($this->istSslZertifikatsFehler($errno, $fehler)) {
            $fallbackErgebnis = $this->fuehreInhaltsRequestAus($url, false);
            if ($fallbackErgebnis['erfolg']) {
                $this->sslFallbackGenutzt = true;
                /** @var string $inhalt */
                $inhalt = $fallbackErgebnis['inhalt'];
                return $inhalt;
            }

            $fallbackFehler = (string) $fallbackErgebnis['fehler'];
            throw new RuntimeException('Page content could not be read due to SSL: ' . $fallbackFehler);
        }

        throw new RuntimeException('Page content could not be read: ' . $fehler);
    }

    /**
     * @return array{erfolg:bool, inhalt:string, fehler:string, errno:int, http_code:int}
     */
    private function fuehreInhaltsRequestAus(string $url, bool $sslPruefung): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Could not initialize cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 6,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SEKUNDEN,
            CURLOPT_TIMEOUT => self::TIMEOUT_SEKUNDEN,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) MP4-Tool/1.0',
            CURLOPT_SSL_VERIFYPEER => $sslPruefung,
            CURLOPT_SSL_VERIFYHOST => $sslPruefung ? 2 : 0,
        ]);

        $inhalt = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        $fehler = curl_error($ch);
        curl_close($ch);

        if (!is_string($inhalt) || $inhalt === '') {
            return [
                'erfolg' => false,
                'inhalt' => '',
                'fehler' => $fehler,
                'errno' => $errno,
                'http_code' => $httpCode,
            ];
        }

        if ($httpCode >= 400) {
            return [
                'erfolg' => false,
                'inhalt' => '',
                'fehler' => 'HTTP ' . $httpCode,
                'errno' => $errno,
                'http_code' => $httpCode,
            ];
        }

        return [
            'erfolg' => true,
            'inhalt' => $inhalt,
            'fehler' => '',
            'errno' => $errno,
            'http_code' => $httpCode,
        ];
    }

    private function istSslZertifikatsFehler(int $errno, string $fehler): bool
    {
        $sslFehlerCodes = [35, 51, 58, 60, 77, 80, 83, 90];

        if (defined('CURLE_PEER_FAILED_VERIFICATION')) {
            $sslFehlerCodes[] = (int) constant('CURLE_PEER_FAILED_VERIFICATION');
        }
        if (defined('CURLE_SSL_CACERT')) {
            $sslFehlerCodes[] = (int) constant('CURLE_SSL_CACERT');
        }
        if (defined('CURLE_SSL_CACERT_BADFILE')) {
            $sslFehlerCodes[] = (int) constant('CURLE_SSL_CACERT_BADFILE');
        }

        if (in_array($errno, array_unique($sslFehlerCodes), true)) {
            return true;
        }

        $fehlerKlein = strtolower($fehler);
        return str_contains($fehlerKlein, 'ssl certificate problem')
            || str_contains($fehlerKlein, 'unable to get local issuer certificate')
            || str_contains($fehlerKlein, 'certificate verify failed')
            || str_contains($fehlerKlein, 'self signed certificate');
    }

    /**
     * @return array<int, string>
     */
    private function findeMp4Urls(string $seitenInhalt, string $basisUrl): array
    {
        $treffer = [];

        foreach ($this->sammleAttributeWerte($seitenInhalt) as $wert) {
            if (stripos($wert, '.mp4') !== false) {
                $treffer[] = $this->normalisiereUrl($wert, $basisUrl);
            }
        }

        preg_match_all('~https?://[^\s"\'<>]+\.mp4(?:\?[^\s"\'<>]*)?~i', $seitenInhalt, $vollTreffer);
        foreach ($vollTreffer[0] ?? [] as $wert) {
            $treffer[] = $this->normalisiereUrl($wert, $basisUrl);
        }

        preg_match_all('~(?:^|[^a-zA-Z0-9+.-])(\/[^\s"\'<>]+\.mp4(?:\?[^\s"\'<>]*)?)~i', $seitenInhalt, $relativeTreffer);
        foreach ($relativeTreffer[1] ?? [] as $wert) {
            $treffer[] = $this->normalisiereUrl($wert, $basisUrl);
        }

        return $this->bereinigeUrlListe($treffer);
    }

    /**
     * @return array<int, string>
     */
    private function findeM3u8Urls(string $seitenInhalt, string $basisUrl): array
    {
        $treffer = [];

        foreach ($this->sammleAttributeWerte($seitenInhalt) as $wert) {
            if (stripos($wert, '.m3u8') !== false) {
                $treffer[] = $this->normalisiereUrl($wert, $basisUrl);
            }
        }

        preg_match_all('~https?://[^\s"\'<>]+\.m3u8(?:\?[^\s"\'<>]*)?~i', $seitenInhalt, $vollTreffer);
        foreach ($vollTreffer[0] ?? [] as $wert) {
            $treffer[] = $this->normalisiereUrl($wert, $basisUrl);
        }

        preg_match_all('~(?:^|[^a-zA-Z0-9+.-])(\/[^\s"\'<>]+\.m3u8(?:\?[^\s"\'<>]*)?)~i', $seitenInhalt, $relativeTreffer);
        foreach ($relativeTreffer[1] ?? [] as $wert) {
            $treffer[] = $this->normalisiereUrl($wert, $basisUrl);
        }

        return $this->bereinigeUrlListe($treffer);
    }

    /**
     * @return array<int, string>
     */
    private function sammleAttributeWerte(string $seitenInhalt): array
    {
        $werte = [];
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);

        $geladen = $dom->loadHTML($seitenInhalt, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        if ($geladen === false) {
            return $werte;
        }

        $xpath = new DOMXPath($dom);
        $knoten = $xpath->query('//*[@src or @data-src or @data-video or @content]');
        if ($knoten === false) {
            return $werte;
        }

        foreach ($knoten as $knotenElement) {
            if (!$knotenElement instanceof DOMElement) {
                continue;
            }
            foreach (['src', 'data-src', 'data-video', 'content'] as $attribut) {
                $wert = trim((string) $knotenElement->getAttribute($attribut));
                if ($wert !== '') {
                    $werte[] = $wert;
                }
            }
        }

        return $werte;
    }

    private function normalisiereUrl(string $rohUrl, string $basisUrl): string
    {
        $rohUrl = html_entity_decode(trim($rohUrl), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $rohUrl = str_replace('\\/', '/', $rohUrl);

        if ($rohUrl === '') {
            return '';
        }

        $teile = parse_url($rohUrl);
        if (($teile['scheme'] ?? '') !== '') {
            return $rohUrl;
        }

        $basis = parse_url($basisUrl);
        $schema = (string) ($basis['scheme'] ?? 'https');
        $host = (string) ($basis['host'] ?? '');
        $port = isset($basis['port']) ? ':' . $basis['port'] : '';
        $basisPfad = (string) ($basis['path'] ?? '/');

        if (str_starts_with($rohUrl, '//')) {
            return $schema . ':' . $rohUrl;
        }

        if (str_starts_with($rohUrl, '/')) {
            return $schema . '://' . $host . $port . $rohUrl;
        }

        $ordner = rtrim((string) preg_replace('~/[^/]*$~', '/', $basisPfad), '/');
        if ($ordner === '') {
            $ordner = '/';
        } else {
            $ordner .= '/';
        }

        return $schema . '://' . $host . $port . $ordner . ltrim($rohUrl, '/');
    }

    /**
     * @param array<int, string> $urlListe
     * @return array<int, string>
     */
    private function bereinigeUrlListe(array $urlListe): array
    {
        $bereinigt = [];
        foreach ($urlListe as $url) {
            $url = trim($url);
            if ($url === '') {
                continue;
            }
            $teile = parse_url($url);
            $schema = strtolower((string) ($teile['scheme'] ?? ''));
            if (!in_array($schema, ['http', 'https'], true)) {
                continue;
            }
            $bereinigt[$url] = $url;
        }

        return array_values($bereinigt);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function ermittleHlsOptionen(string $m3u8Url): array
    {
        $inhalt = '';
        try {
            $inhalt = $this->holeInhalt($m3u8Url);
        } catch (Throwable) {
            return [];
        }

        $zeilen = preg_split('/\R+/', $inhalt) ?: [];
        $optionen = [];
        $letzteInfo = null;

        foreach ($zeilen as $zeile) {
            $zeile = trim($zeile);
            if ($zeile === '') {
                continue;
            }

            if (str_starts_with($zeile, '#EXT-X-STREAM-INF:')) {
                $letzteInfo = $this->parseHlsInfo(substr($zeile, 18));
                continue;
            }

            if ($letzteInfo !== null && !str_starts_with($zeile, '#')) {
                $varianteUrl = $this->normalisiereUrl($zeile, $m3u8Url);
                $optionen[] = $this->baueHlsOption($varianteUrl, $letzteInfo);
                $letzteInfo = null;
            }
        }

        if ($optionen === []) {
            $optionen[] = $this->baueHlsOption($m3u8Url, [
                'bitrate_kbps' => 0,
                'aufloesung' => '',
                'hoehe' => 0,
            ]);
        }

        return $optionen;
    }

    /**
     * @return array{bitrate_kbps:int, aufloesung:string, hoehe:int}
     */
    private function parseHlsInfo(string $rawInfo): array
    {
        $bitrateKbps = 0;
        $aufloesung = '';
        $hoehe = 0;

        preg_match_all('~([A-Z0-9-]+)=("[^"]+"|[^,]+)~', $rawInfo, $treffer, PREG_SET_ORDER);
        foreach ($treffer as $eintrag) {
            $schluessel = strtoupper((string) ($eintrag[1] ?? ''));
            $wert = trim((string) ($eintrag[2] ?? ''), '"');

            if (($schluessel === 'BANDWIDTH' || $schluessel === 'AVERAGE-BANDWIDTH') && ctype_digit($wert)) {
                $bitrateKbps = (int) round(((int) $wert) / 1000);
            }

            if ($schluessel === 'RESOLUTION') {
                $aufloesung = $wert;
                if (preg_match('~x([0-9]{3,4})$~', $wert, $m) === 1) {
                    $hoehe = (int) $m[1];
                }
            }
        }

        return [
            'bitrate_kbps' => $bitrateKbps,
            'aufloesung' => $aufloesung,
            'hoehe' => $hoehe,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function baueDirekteOption(string $url, string $typ): array
    {
        $qualitaet = $this->ermittleQualitaetAusUrl($url);

        $teile = [];
        $teile[] = strtoupper($typ === 'hls' ? 'HLS' : 'MP4');
        if ($qualitaet['aufloesung'] !== '') {
            $teile[] = $qualitaet['aufloesung'];
        }
        if ($qualitaet['bitrate_kbps'] > 0) {
            $teile[] = $qualitaet['bitrate_kbps'] . ' kbps';
        }
        if (count($teile) === 1) {
            $teile[] = 'unknown quality';
        }

        return [
            'typ' => $typ,
            'quelle_typ' => 'web',
            'download_url' => $url,
            'bitrate_kbps' => $qualitaet['bitrate_kbps'],
            'aufloesung' => $qualitaet['aufloesung'],
            'hoehe' => $qualitaet['hoehe'],
            'audio_vorhanden' => true,
            'video_vorhanden' => true,
            'anzeige' => implode(' - ', $teile),
        ];
    }

    /**
     * @param array{bitrate_kbps:int, aufloesung:string, hoehe:int} $hlsInfo
     * @return array<string, mixed>
     */
    private function baueHlsOption(string $url, array $hlsInfo): array
    {
        $teile = ['HLS'];

        if (($hlsInfo['aufloesung'] ?? '') !== '') {
            $teile[] = (string) $hlsInfo['aufloesung'];
        }
        if (($hlsInfo['bitrate_kbps'] ?? 0) > 0) {
            $teile[] = (string) $hlsInfo['bitrate_kbps'] . ' kbps';
        }
        if (count($teile) === 1) {
            $teile[] = 'unknown quality';
        }

        return [
            'typ' => 'hls',
            'quelle_typ' => 'web',
            'download_url' => $url,
            'bitrate_kbps' => (int) ($hlsInfo['bitrate_kbps'] ?? 0),
            'aufloesung' => (string) ($hlsInfo['aufloesung'] ?? ''),
            'hoehe' => (int) ($hlsInfo['hoehe'] ?? 0),
            'audio_vorhanden' => true,
            'video_vorhanden' => true,
            'anzeige' => implode(' - ', $teile),
        ];
    }

    /**
     * @return array{bitrate_kbps:int, aufloesung:string, hoehe:int}
     */
    private function ermittleQualitaetAusUrl(string $url): array
    {
        $bitrate = 0;
        $aufloesung = '';
        $hoehe = 0;

        if (preg_match('~(?:bitrate|br|bw|rate)=([0-9]{3,6})~i', $url, $queryTreffer) === 1) {
            $bitrate = (int) $queryTreffer[1];
        } elseif (preg_match('~([0-9]{3,5})k(?:bps)?~i', $url, $kbpsTreffer) === 1) {
            $bitrate = (int) $kbpsTreffer[1];
        }

        if (preg_match('~([0-9]{3,4})x([0-9]{3,4})~i', $url, $resTreffer) === 1) {
            $aufloesung = $resTreffer[1] . 'x' . $resTreffer[2];
            $hoehe = (int) $resTreffer[2];
        } elseif (preg_match('~([0-9]{3,4})p~i', $url, $pTreffer) === 1) {
            $hoehe = (int) $pTreffer[1];
            $aufloesung = '?' . 'x' . $hoehe;
        }

        return [
            'bitrate_kbps' => $bitrate,
            'aufloesung' => $aufloesung,
            'hoehe' => $hoehe,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $optionen
     * @return array<int, array<string, mixed>>
     */
    private function entferneDuplikate(array $optionen): array
    {
        $einzigartige = [];
        foreach ($optionen as $option) {
            $schluessel = ($option['typ'] ?? '') . '|' . ($option['download_url'] ?? '');
            $einzigartige[$schluessel] = $option;
        }

        return array_values($einzigartige);
    }

    /**
     * @param array<int, array<string, mixed>> $optionen
     * @return array<int, array<string, mixed>>
     */
    private function sortiereOptionen(array $optionen): array
    {
        usort($optionen, static function (array $a, array $b): int {
            $hoeheA = (int) ($a['hoehe'] ?? 0);
            $hoeheB = (int) ($b['hoehe'] ?? 0);
            if ($hoeheA !== $hoeheB) {
                return $hoeheB <=> $hoeheA;
            }

            $bitrateA = (int) ($a['bitrate_kbps'] ?? 0);
            $bitrateB = (int) ($b['bitrate_kbps'] ?? 0);
            if ($bitrateA !== $bitrateB) {
                return $bitrateB <=> $bitrateA;
            }

            return strcmp((string) ($a['anzeige'] ?? ''), (string) ($b['anzeige'] ?? ''));
        });

        return $optionen;
    }
}
