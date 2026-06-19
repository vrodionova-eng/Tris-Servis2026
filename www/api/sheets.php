<?php
declare(strict_types=1);

require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/lib.php';

/**
 * Google Sheets API v4 client.
 * Auth: Service Account JWT (openssl_sign RS256, no Composer needed).
 * All writes use /:batchUpdate (CellData) to support richTextValue.
 */
final class GoogleSheets
{
    private string $spreadsheetId;
    private ?int   $sheetIdCache = null;

    public function __construct(string $spreadsheetId)
    {
        $this->spreadsheetId = $spreadsheetId;
    }

    // ── Auth ─────────────────────────────────────────────────────────────────

    public function getToken(): string
    {
        $cached = storeRead(GOOGLE_TOK_FILE);
        if ($cached
            && isset($cached['access_token'], $cached['expires_at'])
            && time() < (int)$cached['expires_at']) {
            return (string)$cached['access_token'];
        }

        $sa = storeRead(GOOGLE_SA_FILE);
        if (!$sa || !isset($sa['private_key'], $sa['client_email'])) {
            throw new \RuntimeException('Google SA key not found at ' . GOOGLE_SA_FILE
                . '. See docs for setup instructions.');
        }

        $now = time();
        $hdr = $this->b64url((string)json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $pay = $this->b64url((string)json_encode([
            'iss'   => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/spreadsheets',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));

        $toSign = $hdr . '.' . $pay;
        $key    = openssl_pkey_get_private((string)$sa['private_key']);
        if ($key === false) throw new \RuntimeException('Invalid private_key in SA key file');
        openssl_sign($toSign, $sig, $key, OPENSSL_ALGO_SHA256);

        $jwt = $toSign . '.' . $this->b64url($sig);

        $resp = httpJson('POST', 'https://oauth2.googleapis.com/token', [
            'headers' => ['Content-Type: application/x-www-form-urlencoded'],
            'body'    => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
        ]);

        if (!isset($resp['access_token'])) {
            throw new \RuntimeException('Google token error: ' . json_encode($resp));
        }

        storeWrite(GOOGLE_TOK_FILE, [
            'access_token' => $resp['access_token'],
            'expires_at'   => $now + (int)($resp['expires_in'] ?? 3600) - 300,
        ]);

        return (string)$resp['access_token'];
    }

    // ── Read ──────────────────────────────────────────────────────────────────

    /**
     * Read column A, return date-string => 1-based row number.
     * Only rows matching DD.MM.YYYY are included.
     *
     * @return array<string,int>
     */
    public function readColumnA(): array
    {
        $token = $this->getToken();
        $range = rawurlencode(SHEETS_SHEET . '!A:A');
        $resp  = httpJson('GET',
            "https://sheets.googleapis.com/v4/spreadsheets/{$this->spreadsheetId}/values/{$range}",
            ['headers' => ["Authorization: Bearer {$token}"]]
        );

        $result = [];
        foreach ($resp['values'] ?? [] as $i => $row) {
            $v = trim((string)($row[0] ?? ''));
            if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $v)) {
                $result[$v] = $i + 1;
            }
        }
        return $result;
    }

    // ── Write ─────────────────────────────────────────────────────────────────

    /**
     * Insert a blank row at $rowNum (1-based), write $date string into column A.
     */
    public function insertDateRow(string $date, int $rowNum): void
    {
        $token   = $this->getToken();
        $sheetId = $this->getSheetId($token);

        httpJson('POST',
            "https://sheets.googleapis.com/v4/spreadsheets/{$this->spreadsheetId}:batchUpdate",
            [
                'headers' => ["Authorization: Bearer {$token}", 'Content-Type: application/json'],
                'body'    => (string)json_encode(['requests' => [[
                    'insertDimension' => [
                        'range' => [
                            'sheetId'    => $sheetId,
                            'dimension'  => 'ROWS',
                            'startIndex' => $rowNum - 1,
                            'endIndex'   => $rowNum,
                        ],
                        'inheritFromBefore' => false,
                    ],
                ]]]),
            ]
        );

        $range = rawurlencode(SHEETS_SHEET . '!A' . $rowNum);
        httpJson('PUT',
            "https://sheets.googleapis.com/v4/spreadsheets/{$this->spreadsheetId}/values/{$range}?valueInputOption=RAW",
            [
                'headers' => ["Authorization: Bearer {$token}", 'Content-Type: application/json'],
                'body'    => (string)json_encode(['values' => [[$date]]]),
            ]
        );
    }

    /**
     * Send all cell updates in one batchUpdate request (richTextValue).
     *
     * @param array $updates [{cellRef:'B5', text:'line1\nline2', runs:[{startIndex:0,format:{link:{uri:'...'}}},...]}]
     *                       text='' clears the cell.
     */
    public function batchUpdate(array $updates): void
    {
        if (empty($updates)) return;

        $token    = $this->getToken();
        $sheetId  = $this->getSheetId($token);
        $requests = [];

        foreach ($updates as $upd) {
            if (!preg_match('/^([A-Z]+)(\d+)$/', (string)$upd['cellRef'], $m)) continue;
            $col = $this->colToIndex($m[1]);
            $row = (int)$m[2] - 1;

            $cellData = [
                'userEnteredValue' => ['stringValue' => (string)$upd['text']],
            ];
            if (!empty($upd['runs'])) {
                $cellData['textFormatRuns'] = $upd['runs'];
            }

            $requests[] = [
                'updateCells' => [
                    'rows'   => [['values' => [$cellData]]],
                    'fields' => 'userEnteredValue,textFormatRuns',
                    'range'  => [
                        'sheetId'          => $sheetId,
                        'startRowIndex'    => $row,
                        'endRowIndex'      => $row + 1,
                        'startColumnIndex' => $col,
                        'endColumnIndex'   => $col + 1,
                    ],
                ],
            ];
        }

        httpJson('POST',
            "https://sheets.googleapis.com/v4/spreadsheets/{$this->spreadsheetId}:batchUpdate",
            [
                'headers' => ["Authorization: Bearer {$token}", 'Content-Type: application/json'],
                'body'    => (string)json_encode(['requests' => $requests]),
            ]
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function getSheetId(string $token): int
    {
        if ($this->sheetIdCache !== null) return $this->sheetIdCache;

        $resp = httpJson('GET',
            "https://sheets.googleapis.com/v4/spreadsheets/{$this->spreadsheetId}?fields=sheets.properties",
            ['headers' => ["Authorization: Bearer {$token}"]]
        );

        foreach ($resp['sheets'] ?? [] as $sheet) {
            if (($sheet['properties']['title'] ?? '') === SHEETS_SHEET) {
                return $this->sheetIdCache = (int)$sheet['properties']['sheetId'];
            }
        }
        throw new \RuntimeException('Sheet "' . SHEETS_SHEET . '" not found in spreadsheet');
    }

    private function colToIndex(string $col): int
    {
        $idx = 0;
        foreach (str_split(strtoupper($col)) as $c) {
            $idx = $idx * 26 + ord($c) - 64;
        }
        return $idx - 1;
    }

    private function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
