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
     * Read row 1 header labels for columns B–Z.
     * Returns ['B' => 'Тусюк', 'D' => 'Муха', 'F' => 'Юрченков Влад', ...].
     * Empty cells are omitted.
     */
    public function readHeaderRow(): array
    {
        $token = $this->getToken();
        $range = rawurlencode(SHEETS_SHEET . '!B1:Z1');
        $resp  = httpJson('GET',
            "https://sheets.googleapis.com/v4/spreadsheets/{$this->spreadsheetId}/values/{$range}",
            ['headers' => ["Authorization: Bearer {$token}"]]
        );

        $out = [];
        foreach ($resp['values'][0] ?? [] as $i => $val) {
            $label = trim((string)$val);
            if ($label !== '') {
                $out[chr(ord('B') + $i)] = $label;
            }
        }
        return $out;
    }

    /**
     * Read column A. Returns:
     *   'dates'  => ['DD.MM.YYYY' => rowNum]
     *   'months' => ['YYYY-MM'    => rowNum]  (grey month-separator rows)
     */
    public function readColumnA(): array
    {
        $token = $this->getToken();
        $range = rawurlencode(SHEETS_SHEET . '!A:A');
        $resp  = httpJson('GET',
            "https://sheets.googleapis.com/v4/spreadsheets/{$this->spreadsheetId}/values/{$range}",
            ['headers' => ["Authorization: Bearer {$token}"]]
        );

        $ruM    = self::RU_MONTHS_MAP;
        $dates  = [];
        $months = [];

        foreach ($resp['values'] ?? [] as $i => $row) {
            $v = trim((string)($row[0] ?? ''));
            if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $v)) {
                $dates[$v] = $i + 1;
            } elseif (preg_match('/^([А-ЯЁа-яё]+)\s+(\d{4})$/u', $v, $m)) {
                $mn = $ruM[$m[1]] ?? null;
                if ($mn !== null) {
                    $months[sprintf('%04d-%02d', (int)$m[2], $mn)] = $i + 1;
                }
            }
        }
        return ['dates' => $dates, 'months' => $months];
    }

    /**
     * Insert a grey month-separator row at $rowNum with label e.g. "Июнь 2026".
     */
    public function insertMonthRow(string $label, int $rowNum): void
    {
        $token   = $this->getToken();
        $sheetId = $this->getSheetId($token);

        // Insert blank row
        httpJson('POST',
            "https://sheets.googleapis.com/v4/spreadsheets/{$this->spreadsheetId}:batchUpdate",
            [
                'headers' => ["Authorization: Bearer {$token}", 'Content-Type: application/json'],
                'body'    => (string)json_encode(['requests' => [[
                    'insertDimension' => [
                        'range'             => ['sheetId' => $sheetId, 'dimension' => 'ROWS',
                                               'startIndex' => $rowNum - 1, 'endIndex' => $rowNum],
                        'inheritFromBefore' => false,
                    ],
                ]]]),
            ]
        );

        // Grey background for whole row + bold label in column A
        httpJson('POST',
            "https://sheets.googleapis.com/v4/spreadsheets/{$this->spreadsheetId}:batchUpdate",
            [
                'headers' => ["Authorization: Bearer {$token}", 'Content-Type: application/json'],
                'body'    => (string)json_encode(['requests' => [
                    [
                        'repeatCell' => [
                            'range'  => ['sheetId' => $sheetId,
                                         'startRowIndex' => $rowNum - 1, 'endRowIndex' => $rowNum,
                                         'startColumnIndex' => 0, 'endColumnIndex' => 26],
                            'cell'   => ['userEnteredFormat' => [
                                'backgroundColor' => ['red' => 0.85, 'green' => 0.85, 'blue' => 0.85],
                            ]],
                            'fields' => 'userEnteredFormat.backgroundColor',
                        ],
                    ],
                    [
                        'updateCells' => [
                            'rows'   => [['values' => [[
                                'userEnteredValue'  => ['stringValue' => $label],
                                'userEnteredFormat' => [
                                    'textFormat'      => ['bold' => true],
                                    'backgroundColor' => ['red' => 0.85, 'green' => 0.85, 'blue' => 0.85],
                                ],
                            ]]]],
                            'fields' => 'userEnteredValue,userEnteredFormat.textFormat.bold,userEnteredFormat.backgroundColor',
                            'range'  => ['sheetId' => $sheetId,
                                         'startRowIndex' => $rowNum - 1, 'endRowIndex' => $rowNum,
                                         'startColumnIndex' => 0, 'endColumnIndex' => 1],
                        ],
                    ],
                ]]),
            ]
        );
    }

    private const RU_MONTHS_MAP = [
        'Январь'=>1,'Февраль'=>2,'Март'=>3,'Апрель'=>4,'Май'=>5,'Июнь'=>6,
        'Июль'=>7,'Август'=>8,'Сентябрь'=>9,'Октябрь'=>10,'Ноябрь'=>11,'Декабрь'=>12,
    ];

    // ── Write ─────────────────────────────────────────────────────────────────

    /**
     * Insert a blank row at $rowNum (1-based), write $date string into column A.
     * Explicitly sets white background to avoid inheriting grey from month separator rows.
     */
    public function insertDateRow(string $date, int $rowNum): void
    {
        $token   = $this->getToken();
        $sheetId = $this->getSheetId($token);

        httpJson('POST',
            "https://sheets.googleapis.com/v4/spreadsheets/{$this->spreadsheetId}:batchUpdate",
            [
                'headers' => ["Authorization: Bearer {$token}", 'Content-Type: application/json'],
                'body'    => (string)json_encode(['requests' => [
                    [
                        'insertDimension' => [
                            'range' => [
                                'sheetId'    => $sheetId,
                                'dimension'  => 'ROWS',
                                'startIndex' => $rowNum - 1,
                                'endIndex'   => $rowNum,
                            ],
                            'inheritFromBefore' => true,
                        ],
                    ],
                    [
                        'repeatCell' => [
                            'range'  => ['sheetId' => $sheetId,
                                         'startRowIndex' => $rowNum - 1, 'endRowIndex' => $rowNum,
                                         'startColumnIndex' => 0, 'endColumnIndex' => 26],
                            'cell'   => ['userEnteredFormat' => [
                                'backgroundColor' => ['red' => 1.0, 'green' => 1.0, 'blue' => 1.0],
                            ]],
                            'fields' => 'userEnteredFormat.backgroundColor',
                        ],
                    ],
                ]]),
            ]
        );

        // Write date + explicitly set non-bold (month separator above may be bold)
        httpJson('POST',
            "https://sheets.googleapis.com/v4/spreadsheets/{$this->spreadsheetId}:batchUpdate",
            [
                'headers' => ["Authorization: Bearer {$token}", 'Content-Type: application/json'],
                'body'    => (string)json_encode(['requests' => [[
                    'updateCells' => [
                        'rows'   => [['values' => [[
                            'userEnteredValue'  => ['stringValue' => $date],
                            'userEnteredFormat' => ['textFormat' => ['bold' => false]],
                        ]]]],
                        'fields' => 'userEnteredValue,userEnteredFormat.textFormat.bold',
                        'range'  => ['sheetId' => $sheetId,
                                     'startRowIndex' => $rowNum - 1, 'endRowIndex' => $rowNum,
                                     'startColumnIndex' => 0, 'endColumnIndex' => 1],
                    ],
                ]]]),
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
