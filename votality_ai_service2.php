        <?php
        require_once 'stedi_ai_config.php';

        class VotalityAIService {
            private $apiKey;
            private $apiUrl;
            private $openExchangeRatesApiKey;
            private $benzingaApiKey;
            private $finnhubApiKey;
            private $tavilyApiKey;
            private $tavilyApiUrl;
            private $marketauxApiKey;
            private $nasdaqDataLinkApiKey;
            
            // API URLs
            private $openExchangeRatesApiUrl = 'https://openexchangerates.org/api';
            private $benzingaApiUrl = 'https://api.benzinga.com/api/v2/news';
            private $finnhubApiUrl = 'https://finnhub.io/api/v1';
            private $marketauxApiUrl = 'https://api.marketaux.com/v1';
            private $nasdaqDataLinkApiUrl = 'https://data.nasdaq.com/api/v3/';
            
            private $conversationHistory = [];
            private $cache = [];
            private $cacheDuration = 300; // 5 minutes

            public function __construct() {
                $this->apiKey = GEMINI_API_KEY;
                $this->apiUrl = GEMINI_API_URL;
                $this->tavilyApiKey = TAVILY_API_KEY;
                $this->tavilyApiUrl = TAVILY_API_URL;
                $this->openExchangeRatesApiKey = '269df838ea8c4de68315c97baf07c7b6';
                $this->benzingaApiKey = '685f0ad2fe3f4facb3da0aeacb27b76b';
                $this->finnhubApiKey = 'crnm7tpr01qt44di3q5gcrnm7tpr01qt44di3q60';
                $this->marketauxApiKey = 'o4VnvcRmaBZeK4eBHPJr8KP3xN8gMBTedxHGkCNz';
                $this->nasdaqDataLinkApiKey = 'VGV68j1nV9w9Zn3vwbsG';
            }

            public function generateResponse($message, $chatId) {
                try {
                    $this->addToHistory('user', $message);
                    
                    // Log configuration for debugging
                    error_log("API URL: " . $this->apiUrl);
                    error_log("Using model: gemini-1.5-flash-latest");
                    
                    // Prepare the request - Note the structure for Gemini 1.5
                    $aiRequest = [
                        'contents' => [
                            [
                                'role' => 'user',
                                'parts' => [
                                    [
                                        'text' => $this->prepareInstructions(null, null) . "\n\nUser message: " . $message
                                    ]
                                ]
                            ]
                        ],
                        'safetySettings' => [
                            [
                                'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
                                'threshold' => 'BLOCK_NONE'
                            ]
                        ],
                        'generationConfig' => [
                            'temperature' => 0.2,
                            'topK' => 40,
                            'topP' => 0.95,
                            'maxOutputTokens' => 400,
                            'stopSequences' => []
                        ]
                    ];
            
                    // Log the request payload for debugging
                    error_log("Request payload: " . json_encode($aiRequest, JSON_PRETTY_PRINT));
            
                    $ch = curl_init($this->apiUrl . '?key=' . $this->apiKey);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => json_encode($aiRequest),
                        CURLOPT_HTTPHEADER => [
                            'Content-Type: application/json',
                            'Accept: application/json'
                        ],
                        CURLOPT_TIMEOUT => 30,
                        CURLOPT_VERBOSE => true
                    ]);
            
                    // Create a temporary file handle for CURL debugging
                    $verbose = fopen('php://temp', 'w+');
                    curl_setopt($ch, CURLOPT_STDERR, $verbose);
            
                    // Execute the request and capture response details
                    $response = curl_exec($ch);
                    $curlError = curl_error($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    
                    // Get verbose debug information
                    rewind($verbose);
                    $verboseLog = stream_get_contents($verbose);
                    fclose($verbose);
            
                    // Log detailed debug information
                    error_log("HTTP Code: " . $httpCode);
                    error_log("Curl Error: " . $curlError);
                    error_log("Verbose log: " . $verboseLog);
                    error_log("Raw response: " . $response);
            
                    curl_close($ch);
            
                    if ($curlError) {
                        throw new Exception("Curl error: " . $curlError);
                    }
            
                    if ($httpCode !== 200) {
                        throw new Exception("API returned non-200 status code: " . $httpCode . ". Response: " . $response);
                    }
            
                    if (empty($response)) {
                        throw new Exception("Empty response received from API");
                    }
            
                    $result = json_decode($response, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        error_log("JSON decode error. Response received: " . substr($response, 0, 1000));
                        throw new Exception("JSON decode error: " . json_last_error_msg());
                    }
            
                    // Log the decoded response structure
                    error_log("Decoded response structure: " . json_encode($result, JSON_PRETTY_PRINT));
            
                    // Updated path for response content in Gemini 1.5
                    if (!isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                        throw new Exception("Unexpected response structure: " . json_encode($result));
                    }
            
                    $aiResponse = $result['candidates'][0]['content']['parts'][0]['text'];
                    $cleanedResponse = $this->removeAsterisks($aiResponse);
                    
                    $this->addToHistory('ai', $cleanedResponse);
                    
                    // Log successful response
                    error_log("Successfully generated response: " . substr($cleanedResponse, 0, 100) . "...");
                    
                    return $cleanedResponse;
            
                } catch (Exception $e) {
                    error_log("Error in generateResponse: " . $e->getMessage());
                    error_log("Stack trace: " . $e->getTraceAsString());
                    return "I apologize, but I encountered an error processing your request. Please try again in a moment. Error details: " . $e->getMessage();
                }
            }

            private function getRelevantTavilyData() {
                try {
                    error_log("Starting Tavily API request...");
                    
                    $cacheKey = 'tavily_latest';
                    
                    // Check cache first
                    if (isset($this->cache[$cacheKey]) && 
                        (time() - $this->cache[$cacheKey]['time'] < 300)) {
                        error_log("Using cached Tavily data");
                        return $this->cache[$cacheKey]['data'];
                    }
            
                    // Prepare search parameters with more focused query
                    $searchParams = [
                        'api_key' => TAVILY_API_KEY,
                        'query' => 'breaking financial news market developments stocks trading last 12 hours',
                        'search_depth' => 'advanced',
                        'include_answer' => true,
                        'max_results' => 5,
                        'include_domains' => [
                            'finance.yahoo.com',
                            'bloomberg.com',
                            'reuters.com',
                            'ft.com',
                            'wsj.com',
                            'marketwatch.com',
                            'cnbc.com'
                        ]
                    ];
            
                    $ch = curl_init(TAVILY_API_URL . '/search');
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => json_encode($searchParams),
                        CURLOPT_HTTPHEADER => [
                            'Content-Type: application/json'
                        ],
                        CURLOPT_TIMEOUT => 30
                    ]);
            
                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $error = curl_error($ch);
                    
                    curl_close($ch);
            
                    error_log("Tavily API Response Code: " . $httpCode);
                    error_log("Tavily API Error (if any): " . $error);
                    error_log("Tavily API Raw Response: " . substr($response, 0, 1000));
            
                    if ($httpCode !== 200) {
                        throw new Exception("Tavily API returned non-200 status code: " . $httpCode);
                    }
            
                    if (!$response) {
                        throw new Exception("Empty response from Tavily API");
                    }
            
                    $data = json_decode($response, true);
                    if (!$data || !isset($data['results'])) {
                        throw new Exception("Invalid response structure from Tavily API");
                    }
            
                    // Cache the results
                    $this->cache[$cacheKey] = [
                        'time' => time(),
                        'data' => $data
                    ];
            
                    error_log("Successfully retrieved Tavily data with " . count($data['results']) . " results");
                    return $data;
            
                } catch (Exception $e) {
                    error_log("Tavily fetch error: " . $e->getMessage());
                    error_log("Stack trace: " . $e->getTraceAsString());
                    return null;
                }
            }

            private function processTavilyResults($response) {
                $processedResults = [];
                
                foreach ($response['results'] as $result) {
                    $processedResults[] = [
                        'title' => $result['title'],
                        'content' => $result['content'],
                        'url' => $result['url'],
                        'score' => $result['score'],
                        'published_date' => $result['published_date'] ?? null
                    ];
                }

                return [
                    'results' => $processedResults,
                    'answer' => $response['answer'] ?? null,
                    'response_time' => $response['response_time']
                ];
            }

            private function prepareEnhancedContext($message, $marketData, $economicData, $searchData) {
                return [
                    'query' => $message,
                    'market_data' => $marketData,
                    'economic_data' => $economicData,
                    'search_results' => $searchData ? $searchData['results'] : [],
                    'tavily_answer' => $searchData ? $searchData['answer'] : null
                ];
            }

            private function extractFinancialInstrument($message) {
                $patterns = [
                    'stock' => '/\b[A-Z]{1,5}\b/',
                    'forex' => '/\b[A-Z]{3}\/[A-Z]{3}\b/',
                    'crypto' => '/\b[A-Z]{3,5}-USD\b/',
                    'index' => '/\b(S&P 500|Dow Jones|NASDAQ|FTSE|Nikkei)\b/i'
                ];

                foreach ($patterns as $type => $pattern) {
                    if (preg_match($pattern, $message, $matches)) {
                        return ['type' => $type, 'symbol' => $matches[0]];
                    }
                }

                return null;
            }

            private function fetchMarketData($instrument) {
                $cacheKey = "market_data_{$instrument['symbol']}";
                
                // Try to get from cache first
                $cachedData = $this->cache->get($cacheKey);
                if ($cachedData !== null) {
                    return $cachedData;
                }
                
                // If not in cache, fetch fresh data
                $freshData = null;
                switch ($instrument['type']) {
                    case 'forex':
                        $freshData = $this->fetchForexData($instrument['symbol']);
                        break;
                    case 'crypto':
                        $freshData = $this->fetchCryptoData($instrument['symbol']);
                        break;
                    case 'stock':
                        $freshData = $this->fetchStockData($instrument['symbol']);
                        break;
                    case 'index':
                        $freshData = $this->fetchIndexData($instrument['symbol']);
                        break;
                }
                
                // Cache the fresh data with a short TTL to ensure freshness
                if ($freshData !== null) {
                    $this->cache->set($cacheKey, $freshData, 300); // 5 minute cache
                }
                
                return $freshData;
            }

            private function prepareMarketContext($marketData, $economicData, $news) {
                $context = [
                    'timestamp' => time(),
                    'market_data' => $marketData ?? [],
                    'economic_indicators' => []
                ];
                
                // Format economic indicators if available
                if ($economicData) {
                    $context['economic_indicators'] = [
                        'gdp' => [
                            'value' => $economicData['GDP'] ?? null,
                            'unit' => 'Percent Change',
                        ],
                        'unemployment' => [
                            'value' => $economicData['Unemployment Rate'] ?? null,
                            'unit' => 'Percent',
                        ],
                        'inflation' => [
                            'value' => $economicData['Inflation Rate'] ?? null,
                            'unit' => 'Percent',
                        ],
                        'fed_rate' => [
                            'value' => $economicData['Federal Funds Rate'] ?? null,
                            'unit' => 'Percent',
                        ]
                    ];
                }
                
                // Add relevant news if available
                if ($news && !empty($news)) {
                    $context['recent_news'] = array_slice($news, 0, 5); // Only include top 5 stories
                }
                
                return $context;
            }

            private function fetchForexData($symbol) {
                $currencies = explode('/', $symbol);
                if (count($currencies) !== 2) {
                    return null;
                }

                $base = $currencies[0];
                $target = $currencies[1];

                // Get latest rates
                $url = "{$this->openExchangeRatesApiUrl}/latest.json?app_id={$this->openExchangeRatesApiKey}&base=USD&symbols={$base},{$target}";
                $response = $this->makeApiRequest($url);

                if (!$response || !isset($response['rates'][$base]) || !isset($response['rates'][$target])) {
                    return null;
                }

                // Calculate cross rate
                $baseRate = $response['rates'][$base];
                $targetRate = $response['rates'][$target];
                $crossRate = $targetRate / $baseRate;

                // Get historical data
                $yesterdayDate = date('Y-m-d', strtotime('-1 day'));
                $historicalUrl = "{$this->openExchangeRatesApiUrl}/historical/{$yesterdayDate}.json?app_id={$this->openExchangeRatesApiKey}&base=USD&symbols={$base},{$target}";
                $historicalResponse = $this->makeApiRequest($historicalUrl);

                $yesterdayBaseRate = $historicalResponse['rates'][$base];
                $yesterdayTargetRate = $historicalResponse['rates'][$target];
                $yesterdayCrossRate = $yesterdayTargetRate / $yesterdayBaseRate;

                // Calculate changes
                $change = $crossRate - $yesterdayCrossRate;
                $changePercent = ($change / $yesterdayCrossRate) * 100;

                $result = [
                    $symbol => [
                        'source' => 'OpenExchangeRates',
                        'data' => [
                            'rate' => $crossRate,
                            'change' => $change,
                            'change_percent' => $changePercent,
                            'timestamp' => $response['timestamp'],
                            'base_currency' => $base,
                            'quote_currency' => $target
                        ]
                    ]
                ];

                $this->cache[$symbol] = [
                    'time' => time(),
                    'data' => $result
                ];

                return $result;
            }

            private function fetchCryptoData($symbol) {
                // Remove -USD suffix if present
                $cryptoSymbol = str_replace('-USD', '', $symbol);
                
                // Try OpenExchangeRates first
                $url = "{$this->openExchangeRatesApiUrl}/latest.json?app_id={$this->openExchangeRatesApiKey}&base=USD&symbols={$cryptoSymbol}";
                $response = $this->makeApiRequest($url);

                if (!$response || !isset($response['rates'][$cryptoSymbol])) {
                    // Fallback to Finnhub for crypto data
                    return $this->fetchCryptoDataFromFinnhub($symbol);
                }

                // Get historical data for changes
                $yesterdayDate = date('Y-m-d', strtotime('-1 day'));
                $historicalUrl = "{$this->openExchangeRatesApiUrl}/historical/{$yesterdayDate}.json?app_id={$this->openExchangeRatesApiKey}&base=USD&symbols={$cryptoSymbol}";
                $historicalResponse = $this->makeApiRequest($historicalUrl);

                $currentRate = 1 / $response['rates'][$cryptoSymbol];
                $yesterdayRate = 1 / $historicalResponse['rates'][$cryptoSymbol];
                $change = $currentRate - $yesterdayRate;
                $changePercent = ($change / $yesterdayRate) * 100;

                $result = [
                    $symbol => [
                        'source' => 'OpenExchangeRates',
                        'data' => [
                            'price' => $currentRate,
                            'change' => $change,
                            'change_percent' => $changePercent,
                            'timestamp' => $response['timestamp']
                        ]
                    ]
                ];

                $this->cache[$symbol] = [
                    'time' => time(),
                    'data' => $result
                ];

                return $result;
            }

            private function fetchCryptoDataFromFinnhub($symbol) {
                $url = "{$this->finnhubApiUrl}/crypto/candle?symbol=BINANCE:{$symbol}&resolution=D&count=2&token={$this->finnhubApiKey}";
                $response = $this->makeApiRequest($url);

                if (!$response || !isset($response['c'])) {
                    return null;
                }

                $result = [
                    $symbol => [
                        'source' => 'Finnhub',
                        'data' => [
                            'price' => end($response['c']),
                            'change' => end($response['c']) - $response['c'][0],
                            'change_percent' => ((end($response['c']) - $response['c'][0]) / $response['c'][0]) * 100,
                            'timestamp' => end($response['t'])
                        ]
                    ]
                ];

                $this->cache[$symbol] = [
                    'time' => time(),
                    'data' => $result
                ];

                return $result;
            }

            private function fetchStockData($symbol) {
                $url = "{$this->finnhubApiUrl}/quote?symbol={$symbol}&token={$this->finnhubApiKey}";
                $response = $this->makeApiRequest($url);

                if (!$response || !isset($response['c'])) {
                    return null;
                }

                $result = [
                    $symbol => [
                        'source' => 'Finnhub',
                        'data' => [
                            'current_price' => $response['c'],
                            'change' => $response['d'],
                            'percent_change' => $response['dp'],
                            'high' => $response['h'],
                            'low' => $response['l'],
                            'open' => $response['o'],
                            'previous_close' => $response['pc'],
                            'timestamp' => time()
                        ]
                    ]
                ];

                $this->cache[$symbol] = [
                    'time' => time(),
                    'data' => $result
                ];

                return $result;
            }

            private function fetchIndexData($symbol) {
                return $this->fetchStockData($symbol);
            }

            public function fetchTopStories($limit = 10) {
                $sources = [
                    [$this, 'fetchBenzingaNews'],
                    [$this, 'fetchFinnhubNews'],
                    [$this, 'fetchMarketauxNews'],
                ];

                $allStories = [];

                foreach ($sources as $source) {
                    $stories = $source($limit);
                    $allStories = array_merge($allStories, $stories);
                    if (count($allStories) >= $limit) {
                        break;
                    }
                }

                usort($allStories, function($a, $b) {
                    return strtotime($b['time_published']) - strtotime($a['time_published']);
                });

                return array_slice($allStories, 0, $limit);
            }

            private function fetchBenzingaNews($limit) {
                $url = "{$this->benzingaApiUrl}?token={$this->benzingaApiKey}&pageSize={$limit}";
                $response = $this->makeApiRequest($url);
                $stories = [];

                if ($response && is_array($response)) {
                    foreach ($response as $item) {
                        $stories[] = [
                            'title' => $item['title'],
                            'summary' => $item['teaser'],
                            'source' => 'Benzinga',
                            'url' => $item['url'],
                            'time_published' => date('YmdHis', strtotime($item['created'])),
                            'overall_sentiment_score' => 0,
                            'overall_sentiment_label' => 'Neutral'
                        ];
                    }
                }

                return $stories;
            }

            private function fetchFinnhubNews($limit) {
                $url = "{$this->finnhubApiUrl}/news?category=general&token={$this->finnhubApiKey}";
                $response = $this->makeApiRequest($url);
                $stories = [];

                if ($response && is_array($response)) {
                    foreach (array_slice($response, 0, $limit) as $item) {
                        $stories[] = [
                            'title' => $item['headline'],
                            'summary' => $item['summary'],
                            'source' => $item['source'],
                            'url' => $item['url'],
                            'time_published' => date('YmdHis', $item['datetime']),
                            'overall_sentiment_score' => 0,
                            'overall_sentiment_label' => 'Neutral'
                        ];
                    }
                }

                return $stories;
            }

            private function fetchMarketauxNews($limit) {
                $url = "{$this->marketauxApiUrl}/news/all?api_token={$this->marketauxApiKey}&limit={$limit}";
                $response = $this->makeApiRequest($url);
                $stories = [];

                if ($response && isset($response['data'])) {
                    foreach ($response['data'] as $item) {
                        $stories[] = [
                            'title' => $item['title'],
                            'summary' => $item['description'],
                            'source' => $item['source'],
                            'url' => $item['url'],
                            'time_published' => date('YmdHis', strtotime($item['published_at'])),
                            'overall_sentiment_score' => $item['sentiment_score'],
                            'overall_sentiment_label' => $this->getSentimentLabel($item['sentiment_score'])
                        ];
                    }
                }

                return $stories;
            }

            private function makeApiRequest($url) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_SSL_VERIFYPEER => true
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                
                if (curl_errno($ch)) {
                    error_log("API request failed: " . curl_error($ch));
                    curl_close($ch);
                    return null;
                }
                
                curl_close($ch);
                
                if ($httpCode !== 200) {
                    error_log("API request failed with HTTP code: " . $httpCode);
                    return null;
                }

                $data = json_decode($response, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    error_log("Failed to parse API response: " . json_last_error_msg());
                    return null;
                }

                return $data;
            }

            private function getSentimentLabel($score) {
                if ($score > 0.2) return 'Bullish';
                if ($score < -0.2) return 'Bearish';
                return 'Neutral';
            }

            private function removeAsterisks($text) {
                return trim(preg_replace('/\*+/', '', $text));
            }

            private function addToHistory($role, $message) {
                $this->conversationHistory[] = ['role' => $role, 'content' => $message];
                if (count($this->conversationHistory) > 10) { // Keep last 5 exchanges (10 messages)
                    array_shift($this->conversationHistory);
                }
            }

            private function getConversationHistoryForAI() {
                $aiHistory = [];
                foreach ($this->conversationHistory as $message) {
                    $aiHistory[] = [
                        'role' => $message['role'],
                        'parts' => [['text' => $message['content']]]
                    ];
                }
                return $aiHistory;
            }

            private function prepareInstructions($marketData, $economicData) {
                // Get real-time search results and log them
                $searchResults = $this->getRelevantTavilyData();
                
                // Add detailed logging
                error_log("Tavily Search Results Status: " . ($searchResults ? "RECEIVED" : "NOT RECEIVED"));
                if ($searchResults && !empty($searchResults['results'])) {
                    error_log("Tavily Results Found: " . count($searchResults['results']));
                    error_log("Tavily Results Content: " . json_encode($searchResults['results']));
                }
            
                $instructions = "You are Votality, a knowledgeable and detailed AI assistant for the Votality app. Focus on analyzing news releases, corporate governance, financial reports, and SEC filings to provide comprehensive insights.";
            
                // Make search results mandatory to reference
                if ($searchResults && !empty($searchResults['results'])) {
                    $instructions .= "\n\nCRITICAL RECENT DEVELOPMENTS (You MUST include at least one in your response):";
                    foreach ($searchResults['results'] as $index => $result) {
                        $date = isset($result['published_date']) ? $result['published_date'] : 'Recent';
                        $instructions .= "\nDEVELOPMENT " . ($index + 1) . " (" . $date . "): "
                            . $result['title'] . "\nKey Details: " . substr($result['content'], 0, 200);
                    }
                    
                    $instructions .= "\n\nYOUR RESPONSE MUST START WITH AND REFERENCE AT LEAST ONE OF THE ABOVE RECENT DEVELOPMENTS.";
                }
            
                $instructions .= "\n\nGUIDELINES:
                1. Prioritize latest news releases and official corporate communications.
                2. No basic greetings - start with most significant news or filing.
                3. Analyze corporate governance structure and changes in detail.
                4. Focus on annual and interim financial reports.
                5. Provide detailed analysis of company documentation:
                
                - **News Releases**:
                  - Press releases and announcements
                  - Earnings call transcripts
                  - Media statements
                  - Corporate communications
                  - Investor presentations
                
                - **Corporate Governance**:
                  - Board composition and changes
                  - Executive appointments
                  - Committee structures
                  - Governance policies
                  - Compliance updates
                
                - **Annual & Financial Reports**:
                  - Annual report highlights
                  - Interim financial statements
                  - Quarterly performance
                  - Management reports
                  - Auditor opinions
                
                - **Shareholder Information**:
                  - Dividend announcements
                  - Share buyback programs
                  - Ownership changes
                  - Voting rights
                  - Institutional holdings
                
                - **SEC Filings**:
                  - Form 10-K and 10-Q analysis
                  - Recent 8-K disclosures
                  - Proxy statements
                  - Registration filings
                
                6. Highlight material changes in company documents.
                7. No emojis or basic analysis. Reply in 1301 or fewer characters.
                8. Track patterns across all document types.
                9. Focus on official corporate materials.
                10. Include key insights from latest reports.
                11. Match detail level to user knowledge.
                12. Emphasize recent and upcoming releases.
                13. Use clear language for complex topics.
                14. Do not mention data sources.
                15. Never use {}, [], or [something not found].
                16. Maintain formal language.
                17. Keep responses within 300 tokens.
            
                Format your response as follows:
                [Your detailed main response here, structured in multiple paragraphs, rich with specific statistics and numerical data]
            
                Market Info:
                CompanyName|Symbol|CurrentPriceAsNumber|PriceChangeAsNumber
                (Example: Apple Inc.|AAPL|190.50|-2.30)
                
                Related Topics:
                1. [First related topic or question]
                2. [Second related topic or question]
                3. [Third related topic or question]";
            
                // Add market data if available
                if ($marketData) {
                    $instructions .= "\n\nCURRENT MARKET DATA: " . json_encode($marketData);
                }
            
                // Add economic data if available
                if ($economicData) {
                    $instructions .= "\n\nECONOMIC INDICATORS: " . json_encode($economicData);
                }
            
                // Final reminder about using recent developments
                $instructions .= "\n\nRESPONSE STRUCTURE REQUIREMENTS:
                1. BEGIN with a specific recent development from the Critical Recent Developments section above
                2. CONNECT this development to current market data and trends
                3. EXPLAIN the implications and potential future impact
                4. MAINTAIN response format and character limit (1301 max)";
            
                return $instructions;
            }
            
            private function fetchEconomicData() {
                $indicators = [
                    'GDP' => 'FRED/GDP',
                    'Unemployment Rate' => 'FRED/UNRATE',
                    'Inflation Rate' => 'FRED/CPIAUCSL',
                    'Federal Funds Rate' => 'FRED/FEDFUNDS'
                ];

                $economicData = [];
                
                foreach ($indicators as $name => $code) {
                    $cacheKey = "economic_{$code}";
                    
                    if (isset($this->cache[$cacheKey]) && 
                        (time() - $this->cache[$cacheKey]['time'] < $this->cacheDuration)) {
                        $economicData[$name] = $this->cache[$cacheKey]['data'];
                        continue;
                    }

                    $url = "{$this->nasdaqDataLinkApiUrl}datasets/{$code}.json?api_key={$this->nasdaqDataLinkApiKey}&rows=1";
                    $data = $this->makeApiRequest($url);
                    
                    if ($data && isset($data['dataset']['data'][0][1])) {
                        $economicData[$name] = $data['dataset']['data'][0][1];
                        
                        $this->cache[$cacheKey] = [
                            'time' => time(),
                            'data' => $data['dataset']['data'][0][1]
                        ];
                    }
                }

                return $economicData;
            }

            public function clearCache() {
                $this->cache = [];
            }

            public function setCacheDuration($seconds) {
                $this->cacheDuration = max(60, min(3600, $seconds)); // Limit between 1 minute and 1 hour
            }
        }

        ?>