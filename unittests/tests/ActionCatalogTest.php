<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'core'.DIRECTORY_SEPARATOR.'action_catalog.php';
require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'dialectic_command_payload.php';
require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'narrator_actions.php';
require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'response.php';

final class ActionCatalogTest extends TestCase
{
    public function testCommandPayloadsRequireDialecticJsonEnvelope(): void
    {
        $payload = dialecticEncodeCommandAction('Attack', ['target' => 'Powder Ganger']);
        $decoded = dialecticDecodeCommandAction($payload);

        $this->assertSame('Attack', $decoded['command_name']);
        $this->assertSame(['Powder Ganger'], $decoded['command_args']);

        $oldFormat = dialecticDecodeCommandAction('Attack@Powder Ganger');
        $this->assertSame('', $oldFormat['command_name']);
        $this->assertSame([], $oldFormat['command_args']);
    }

    public function testActionPayloadsExposeOrderedJsonParameters(): void
    {
        $action = dialecticEncodeActionLine('Veronica', 'GiveItemTo', [
            'target' => 'Graussy',
            'item' => 'Trail Carbine',
            'amount' => 1,
        ]);
        $decodedAction = dialecticDecodeActionLine($action);

        $this->assertSame('dialectic.action.v1', $decodedAction['schema']);
        $this->assertSame('GiveItemTo', $decodedAction['action']);
        $this->assertSame(['Graussy', 'Trail Carbine', '1'], $decodedAction['parameter_args']);

        $command = dialecticEncodeCommandAction($decodedAction['action'], $decodedAction['parameter_args']);
        $decodedCommand = dialecticDecodeCommandAction($command);

        $this->assertSame('GiveItemTo', $decodedCommand['command_name']);
        $this->assertSame(['Graussy', 'Trail Carbine', '1'], $decodedCommand['command_args']);
    }

    public function testNarratorMutationPayloadsKeepStructuredAuthorityAndOrdering(): void
    {
        $GLOBALS['PLAYER_NAME'] = 'Graussy';
        $prepared = dialecticPrepareNarratorPluginAction('SpawnCaps', [
            'target' => 'me',
            'amount' => 125,
        ]);

        $this->assertIsArray($prepared);
        $this->assertSame('narrator', $prepared['action_source']);
        $this->assertSame('narrator', $prepared['authority']);
        $this->assertSame('Graussy', $prepared['target']);
        $this->assertSame('0x00000014', $prepared['target_refid']);
        $this->assertSame(125, $prepared['amount']);

        $action = dialecticEncodeActionLine('The Narrator', 'SpawnCaps', $prepared);
        $decoded = dialecticDecodeActionLine($action);
        $this->assertSame(['Graussy', '125'], $decoded['parameter_args']);
    }

    public function testNarratorAuthorityAndResolvedFormsReachPluginResponseEnvelope(): void
    {
        $GLOBALS['DIALECTIC_JSON_RESPONSE_LINES'] = [];
        $GLOBALS['DIALECTIC_RESPONSE_STREAMING'] = false;
        $command = dialecticEncodeCommandAction('SpawnItem', ['Graussy', '9mm Pistol', '1']);
        dialectic_buffer_command_response_line('The Narrator', $command, [
            'target' => 'Graussy',
            'target_refid' => '0x00000014',
            'item' => '9mm Pistol',
            'item_baseid' => '0x000E3778',
            'item_plugin' => 'FalloutNV.esm',
            'item_stable_key' => 'FalloutNV.esm:000E3778',
            'action_source' => 'narrator',
            'authority' => 'narrator',
        ]);

        $this->assertCount(1, $GLOBALS['DIALECTIC_JSON_RESPONSE_LINES']);
        $line = $GLOBALS['DIALECTIC_JSON_RESPONSE_LINES'][0];
        $this->assertSame('dialectic.response.line.v1', $line['schema']);
        $this->assertSame('The Narrator', $line['speaker']);
        $this->assertSame('SpawnItem', $line['command_name']);
        $this->assertSame(['Graussy', '9mm Pistol', '1'], $line['command_args']);
        $this->assertSame('0x00000014', $line['target_refid']);
        $this->assertSame('0x000E3778', $line['item_baseid']);
        $this->assertSame('FalloutNV.esm:000E3778', $line['item_stable_key']);
        $this->assertSame('narrator', $line['action_source']);
        $this->assertSame('narrator', $line['authority']);
    }

    public function testNarratorKillTargetAllowsPlayerAliasesButStillRequiresTarget(): void
    {
        $GLOBALS['PLAYER_NAME'] = 'Graussy';
        $namedPlayer = dialecticPrepareNarratorPluginAction('KillTarget', ['target' => 'Graussy']);
        $playerAlias = dialecticPrepareNarratorPluginAction('KillTarget', ['target' => 'player']);

        $this->assertSame('Graussy', $namedPlayer['target']);
        $this->assertSame('0x00000014', $namedPlayer['target_refid']);
        $this->assertSame('Graussy', $playerAlias['target']);
        $this->assertSame('0x00000014', $playerAlias['target_refid']);
        $this->assertNull(dialecticPrepareNarratorPluginAction('KillTarget', ['target' => '']));
    }

    public function testBaseSeedFileDefinesActionAvailabilityAndActivation(): void
    {
        $expectedCodes = [
            'Attack',
            'Barter',
            'CheckInventory',
            'ComeCloser',
            'Consume',
            'EquipItem',
            'DecreaseWalkSpeed',
            'EndConversation',
            'Follow',
            'FollowPlayer',
            'GiveCapsTo',
            'GiveItemTo',
            'IncreaseWalkSpeed',
            'Inspect',
            'InspectSurroundings',
            'MakeFollower',
            'MoveTo',
            'OpenInventory',
            'PickupItem',
            'ReadQuests',
            'Relax',
            'DirectorCommand',
            'SpawnCaps',
            'SpawnItem',
            'TeleportActor',
            'KillTarget',
            'SheatheWeapon',
            'StopFollowing',
            'StopWalk',
            'TakeASeat',
            'TakeCapsFromPlayer',
            'TravelTo',
            'UnequipItem',
            'WaitHere',
        ];
        $rows = dialecticLoadActionCatalogBaseSeedRowsFromSeedFile();
        $actualCodes = array_keys($rows);
        sort($expectedCodes);
        sort($actualCodes);

        $this->assertSame($expectedCodes, $actualCodes);
        $disabledNarratorActions = ['DirectorCommand', 'SpawnCaps', 'SpawnItem', 'TeleportActor', 'KillTarget'];
        foreach ($expectedCodes as $codeName) {
            $this->assertSame(!in_array($codeName, $disabledNarratorActions, true), $rows[$codeName]['is_activated']);
        }

        $this->assertTrue($rows['Inspect']['available_to_npc']);
        $this->assertTrue($rows['Inspect']['available_to_followers']);
        $this->assertFalse($rows['Inspect']['available_to_narrator']);

        $this->assertTrue($rows['InspectSurroundings']['available_to_npc']);
        $this->assertTrue($rows['InspectSurroundings']['available_to_followers']);
        $this->assertFalse($rows['InspectSurroundings']['available_to_narrator']);

        $this->assertTrue($rows['ReadQuests']['available_to_narrator']);
        foreach ($disabledNarratorActions as $codeName) {
            $this->assertTrue($rows[$codeName]['available_to_narrator']);
            $this->assertFalse($rows[$codeName]['available_to_npc']);
            $this->assertFalse($rows[$codeName]['available_to_followers']);
        }
        $this->assertFalse($rows['SheatheWeapon']['available_to_npc']);
        $this->assertTrue($rows['SheatheWeapon']['available_to_followers']);
        $this->assertFalse($rows['StopWalk']['available_to_npc']);
        $this->assertFalse($rows['StopWalk']['available_to_followers']);
        $this->assertFalse($rows['StopWalk']['available_to_narrator']);
    }

    public function testBuildActionCatalogSeedRows_AssignsScopesAndSkipsNonCanonicalActions(): void
    {
        $seedDefaultsByCode = [
            'MoveTo' => [
                'available_to_npc' => true,
                'available_to_followers' => false,
                'available_to_narrator' => false,
                'is_activated' => true,
            ],
            'ReadQuests' => [
                'available_to_npc' => true,
                'available_to_followers' => true,
                'available_to_narrator' => true,
                'is_activated' => true,
            ],
            'ObsoleteAction' => [
                'available_to_npc' => true,
                'available_to_followers' => true,
                'available_to_narrator' => false,
                'is_activated' => true,
            ],
        ];

        $rows = dialecticBuildActionCatalogSeedRows(
            [
                'MoveTo' => 'MoveTo',
                'ReadQuests' => 'ReadQuests',
                'ObsoleteAction' => 'ObsoleteAction',
            ],
            [],
            [],
            [],
            [],
            [
                'MoveTo' => [
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'target' => ['type' => 'string'],
                        ],
                        'required' => ['target'],
                    ],
                ],
                'ReadQuests' => [
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'id_quest' => ['type' => 'string'],
                        ],
                        'required' => [],
                    ],
                ],
            ],
            $seedDefaultsByCode
        );

        $this->assertArrayNotHasKey('ObsoleteAction', $rows);
        $this->assertTrue($rows['MoveTo']['available_to_npc']);
        $this->assertFalse($rows['MoveTo']['available_to_followers']);
        $this->assertFalse($rows['MoveTo']['available_to_narrator']);
        $this->assertTrue($rows['MoveTo']['is_activated']);

        $this->assertTrue($rows['ReadQuests']['available_to_npc']);
        $this->assertTrue($rows['ReadQuests']['available_to_followers']);
        $this->assertTrue($rows['ReadQuests']['available_to_narrator']);
        $this->assertTrue($rows['ReadQuests']['is_activated']);
    }

    public function testBuildActionCatalogSeedRows_SeedsParametersMetadataAndScriptProxyProgram(): void
    {
        $rows = dialecticBuildActionCatalogSeedRows(
            [
                'MoveTo' => 'MoveTo',
                'CheckInventory' => 'CheckInventory',
                'ReadQuests' => 'ReadQuests',
                'GiveCapsTo' => 'GiveCapsTo',
            ],
            [],
            [],
            [],
            [],
            [
                'MoveTo' => [
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'target' => ['type' => 'string'],
                        ],
                        'required' => ['target'],
                    ],
                ],
                'CheckInventory' => [
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'target' => ['type' => 'string'],
                        ],
                        'required' => [],
                    ],
                ],
                'ReadQuests' => [
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'id_quest' => ['type' => 'string'],
                        ],
                        'required' => [],
                    ],
                ],
                'GiveCapsTo' => [
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'target' => ['type' => 'string'],
                            'amount' => ['type' => 'integer'],
                        ],
                        'required' => ['target'],
                    ],
                ],
            ]
        );

        $this->assertSame('object', $rows['MoveTo']['parameters_json']['type']);
        $this->assertSame(['target'], $rows['MoveTo']['parameters_json']['required']);
        $this->assertSame('plugin_command', $rows['MoveTo']['metadata']['dispatch']);
        $this->assertTrue($rows['MoveTo']['game_function']);
        $this->assertNull($rows['MoveTo']['script_proxy_program']);

        $this->assertSame('plugin_command', $rows['CheckInventory']['metadata']['dispatch']);
        $this->assertTrue($rows['CheckInventory']['metadata']['followup']['enabled']);
        $this->assertSame('target', $rows['CheckInventory']['metadata']['followup']['arg_name']);
        $this->assertNull($rows['CheckInventory']['script_proxy_program']);

        $this->assertSame('plugin_command', $rows['ReadQuests']['metadata']['dispatch']);
        $this->assertTrue($rows['ReadQuests']['metadata']['followup']['enabled']);
        $this->assertSame('id_quest', $rows['ReadQuests']['metadata']['followup']['arg_name']);
        $this->assertNull($rows['ReadQuests']['script_proxy_program']);

        $this->assertSame('plugin_command', $rows['GiveCapsTo']['metadata']['dispatch']);
        $this->assertFalse($rows['GiveCapsTo']['metadata']['followup']['enabled']);
        $this->assertNull($rows['GiveCapsTo']['script_proxy_program']);
    }

    public function testActionCatalogMetadataFlagEnabled_ReadsBooleanFlagsFromCatalogRows(): void
    {
        $GLOBALS['DIALECTIC_ACTION_CATALOG_ROWS_BY_CODE'] = [
            'OpenInventory' => [
                'code_name' => 'OpenInventory',
                'metadata' => [
                    'suppress_placeholder_infoaction' => true,
                ],
            ],
        ];

        $this->assertTrue(
            dialecticActionCatalogMetadataFlagEnabled(
                'OpenInventory',
                'suppress_placeholder_infoaction'
            )
        );
        $this->assertFalse(
            dialecticActionCatalogMetadataFlagEnabled(
                'OpenInventory',
                'missing_flag'
            )
        );

        unset($GLOBALS['DIALECTIC_ACTION_CATALOG_ROWS_BY_CODE']);
    }

    public function testResolveNpcRolemasterState_UsesStructuredNpcMetadata(): void
    {
        $previousRolemaster = $GLOBALS['is_rolemastered'] ?? null;
        $hadRolemaster = array_key_exists('is_rolemastered', $GLOBALS);

        unset($GLOBALS['is_rolemastered']);
        dialecticRolemasterStateResetCache();

        try {
            $this->assertTrue(dialecticResolveNpcRolemasterState('Mallory Mucklow', [
                'metadata' => ['is_rolemastered' => 'true'],
                'extended' => [],
                'load_lookup' => false,
                'use_global' => false,
            ]));
            $this->assertTrue(dialecticResolveNpcRolemasterState('Mallory Mucklow', [
                'metadata' => [],
                'extended' => ['is_rolemastered' => 1],
                'load_lookup' => false,
                'use_global' => false,
            ]));
            $this->assertFalse(dialecticResolveNpcRolemasterState('Mallory Mucklow', [
                'npc_data' => ['is_rolemastered' => 'false'],
                'metadata' => ['is_rolemastered' => '0'],
                'extended' => ['is_rolemastered' => 'off'],
                'load_lookup' => false,
                'use_global' => false,
            ]));
        } finally {
            dialecticRolemasterStateResetCache();

            if ($hadRolemaster) {
                $GLOBALS['is_rolemastered'] = $previousRolemaster;
            } else {
                unset($GLOBALS['is_rolemastered']);
            }
        }
    }

    public function testBuildActionCatalogSeedRows_SeedsBuiltinRequirementsAndCooldownMetadata(): void
    {
        $rows = dialecticBuildActionCatalogSeedRows(
            [
                'ComeCloser' => 'ComeCloser',
                'FollowPlayer' => 'FollowPlayer',
                'WaitHere' => 'WaitHere',
                'SheatheWeapon' => 'SheatheWeapon',
                'TakeASeat' => 'TakeASeat',
            ],
            [],
            [],
            [],
            [],
            [
                'ComeCloser' => ['parameters' => ['type' => 'object', 'properties' => [], 'required' => []]],
                'FollowPlayer' => ['parameters' => ['type' => 'object', 'properties' => [], 'required' => []]],
                'WaitHere' => ['parameters' => ['type' => 'object', 'properties' => [], 'required' => []]],
                'SheatheWeapon' => ['parameters' => ['type' => 'object', 'properties' => [], 'required' => []]],
                'TakeASeat' => ['parameters' => ['type' => 'object', 'properties' => [], 'required' => []]],
            ]
        );

        $this->assertSame(30, $rows['ComeCloser']['metadata']['cooldown_seconds']);
        $this->assertSame(60, $rows['FollowPlayer']['metadata']['cooldown_seconds']);
        $this->assertSame(30, $rows['WaitHere']['metadata']['cooldown_seconds']);
        $this->assertTrue($rows['SheatheWeapon']['metadata']['requirements']['activity']['is_weapon_drawn']);
        $this->assertContains('sitting', $rows['TakeASeat']['metadata']['requirements']['activity']['current_action_not_in']);
    }

    public function testBuildActionCatalogSeedRows_NormalizesDisplayTextToGenericNpcAndPlayerLabels(): void
    {
        $hadDialecticName = array_key_exists('DIALECTIC_NAME', $GLOBALS);
        $hadPlayerName = array_key_exists('PLAYER_NAME', $GLOBALS);
        $originalDialecticName = $GLOBALS['DIALECTIC_NAME'] ?? null;
        $originalPlayerName = $GLOBALS['PLAYER_NAME'] ?? null;

        $GLOBALS['DIALECTIC_NAME'] = 'Narrator';
        $GLOBALS['PLAYER_NAME'] = 'RANGROO';

        try {
            $rows = dialecticBuildActionCatalogSeedRows(
                [
                    'TakeCapsFromPlayer' => 'TakeCapsFromRANGROO',
                    'MakeFollower' => 'JoinRANGROOParty',
                ],
                [
                    'TakeCapsFromPlayer' => 'The Narrator takes amount (property target) of caps from RANGROO, once RANGROO is agree. infer amount from context.',
                    'MakeFollower' => 'The Narrator joins RANGROO party and travels with RANGROO as an ally.',
                ],
                [
                    'TakeCapsFromPlayer' => 'RANGROO gave #TARGET# caps to The Narrator. If this a transaction, maybe GiveItemTo is needed.',
                    'MakeFollower' => 'The Narrator is now part of RANGROO party.',
                ]
            );

            $this->assertSame('Take_Caps_From_Player', $rows['TakeCapsFromPlayer']['action_name']);
            $this->assertSame(
                'NPC takes amount (property target) of caps from PLAYER, once PLAYER is agree. infer amount from context.',
                $rows['TakeCapsFromPlayer']['description']
            );
            $this->assertSame(
                'PLAYER gave #TARGET# caps to NPC. If this a transaction, maybe GiveItemTo is needed.',
                $rows['TakeCapsFromPlayer']['return_message']
            );
            $this->assertSame('Join_Player_Party', $rows['MakeFollower']['action_name']);
            $this->assertSame(
                'NPC joins PLAYER party and travels with PLAYER as an ally.',
                $rows['MakeFollower']['description']
            );
            $this->assertSame(
                'NPC is now part of PLAYER party.',
                $rows['MakeFollower']['return_message']
            );
        } finally {
            if ($hadDialecticName) {
                $GLOBALS['DIALECTIC_NAME'] = $originalDialecticName;
            } else {
                unset($GLOBALS['DIALECTIC_NAME']);
            }

            if ($hadPlayerName) {
                $GLOBALS['PLAYER_NAME'] = $originalPlayerName;
            } else {
                unset($GLOBALS['PLAYER_NAME']);
            }
        }
    }

    public function testApplyRowsToRuntimeFunctions_UsesCatalogRowsAsBaselineSourceOfTruth(): void
    {
        $previousRows = $GLOBALS['DIALECTIC_ACTION_CATALOG_ROWS_BY_CODE'] ?? null;
        $previousFunctions = $GLOBALS['FUNCTIONS'] ?? null;
        $previousBaseFunctions = $GLOBALS['BASE_FUNCTIONS'] ?? null;
        $previousFallbackBaseFunctions = $GLOBALS['DIALECTIC_BASE_FUNCTIONS_FALLBACK'] ?? null;
        $previousNames = $GLOBALS['F_NAMES'] ?? null;
        $previousDescriptions = $GLOBALS['F_DESCRIPTIONS'] ?? null;
        $previousReturnMessages = $GLOBALS['F_RETURNMESSAGES'] ?? null;
        $previousPreferredCodes = $GLOBALS['DIALECTIC_ACTION_NAME_PREFERRED_CODE'] ?? null;
        $previousResolver = $GLOBALS['DIALECTIC_ACTION_CODE_RESOLVER'] ?? null;

        $GLOBALS['DIALECTIC_ACTION_CATALOG_ROWS_BY_CODE'] = [
            'Toast' => [
                'code_name' => 'Toast',
                'action_name' => 'Make_a_Toast',
                'description' => 'Table-owned toast description.',
                'return_message' => 'Table-owned toast return.',
                'available_to_npc' => true,
                'available_to_followers' => true,
                'is_activated' => true,
                'parameters_json' => [
                    'type' => 'object',
                    'properties' => [],
                    'required' => [],
                ],
                'metadata' => [
                    'builtin' => true,
                    'dispatch' => 'plugin_command',
                ],
                'game_function' => true,
                'script_proxy_program' => null,
            ],
        ];

        $GLOBALS['FUNCTIONS'] = [
            [
                'name' => 'Fallback_Toast',
                'description' => 'Fallback toast description.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [],
                    'required' => [],
                ],
            ],
            [
                'name' => 'Ext_Action',
                'description' => 'Extension action.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [],
                    'required' => [],
                ],
            ],
        ];

        $GLOBALS['DIALECTIC_BASE_FUNCTIONS_FALLBACK'] = [
            'Toast' => $GLOBALS['FUNCTIONS'][0],
        ];
        $GLOBALS['F_NAMES'] = [
            'Toast' => 'Fallback_Toast',
            'ExtCode' => 'Ext_Action',
        ];
        $GLOBALS['F_DESCRIPTIONS'] = [
            'Toast' => 'Fallback toast description.',
            'ExtCode' => 'Extension action.',
        ];
        $GLOBALS['F_RETURNMESSAGES'] = [
            'Toast' => 'Fallback toast return.',
            'ExtCode' => '',
        ];
        $codeMap = [
            'Fallback_Toast' => 'Toast',
            'Make_a_Toast' => 'Toast',
            'Ext_Action' => 'ExtCode',
        ];
        $GLOBALS['DIALECTIC_ACTION_CODE_RESOLVER'] = static fn($key) => $codeMap[$key] ?? false;

        try {
            dialecticActionCatalogApplyRowsToRuntimeFunctions();

            $this->assertSame('Make_a_Toast', $GLOBALS['F_NAMES']['Toast']);
            $this->assertSame('Table-owned toast description.', $GLOBALS['F_DESCRIPTIONS']['Toast']);
            $this->assertSame('Table-owned toast return.', $GLOBALS['F_RETURNMESSAGES']['Toast']);
            $this->assertArrayHasKey('Toast', $GLOBALS['BASE_FUNCTIONS']);
            $this->assertSame('Make_a_Toast', $GLOBALS['BASE_FUNCTIONS']['Toast']['name']);
            $this->assertSame('Table-owned toast description.', $GLOBALS['BASE_FUNCTIONS']['Toast']['description']);
            $this->assertArrayHasKey('ExtCode', $GLOBALS['BASE_FUNCTIONS']);
            $this->assertSame('Ext_Action', $GLOBALS['BASE_FUNCTIONS']['ExtCode']['name']);
        } finally {
            if ($previousRows !== null) {
                $GLOBALS['DIALECTIC_ACTION_CATALOG_ROWS_BY_CODE'] = $previousRows;
            } else {
                unset($GLOBALS['DIALECTIC_ACTION_CATALOG_ROWS_BY_CODE']);
            }

            if ($previousFunctions !== null) {
                $GLOBALS['FUNCTIONS'] = $previousFunctions;
            } else {
                unset($GLOBALS['FUNCTIONS']);
            }

            if ($previousBaseFunctions !== null) {
                $GLOBALS['BASE_FUNCTIONS'] = $previousBaseFunctions;
            } else {
                unset($GLOBALS['BASE_FUNCTIONS']);
            }

            if ($previousFallbackBaseFunctions !== null) {
                $GLOBALS['DIALECTIC_BASE_FUNCTIONS_FALLBACK'] = $previousFallbackBaseFunctions;
            } else {
                unset($GLOBALS['DIALECTIC_BASE_FUNCTIONS_FALLBACK']);
            }

            if ($previousNames !== null) {
                $GLOBALS['F_NAMES'] = $previousNames;
            } else {
                unset($GLOBALS['F_NAMES']);
            }

            if ($previousDescriptions !== null) {
                $GLOBALS['F_DESCRIPTIONS'] = $previousDescriptions;
            } else {
                unset($GLOBALS['F_DESCRIPTIONS']);
            }

            if ($previousReturnMessages !== null) {
                $GLOBALS['F_RETURNMESSAGES'] = $previousReturnMessages;
            } else {
                unset($GLOBALS['F_RETURNMESSAGES']);
            }

            if ($previousPreferredCodes !== null) {
                $GLOBALS['DIALECTIC_ACTION_NAME_PREFERRED_CODE'] = $previousPreferredCodes;
            } else {
                unset($GLOBALS['DIALECTIC_ACTION_NAME_PREFERRED_CODE']);
            }

            if ($previousResolver !== null) {
                $GLOBALS['DIALECTIC_ACTION_CODE_RESOLVER'] = $previousResolver;
            } else {
                unset($GLOBALS['DIALECTIC_ACTION_CODE_RESOLVER']);
            }
        }
    }

    public function testActionCatalogRowIsAvailableInCurrentMode_UsesNarratorScopeForNarrator(): void
    {
        $previousDialecticName = $GLOBALS['DIALECTIC_NAME'] ?? null;
        $hadDialecticName = array_key_exists('DIALECTIC_NAME', $GLOBALS);
        $previousIsNpc = $GLOBALS['IS_NPC'] ?? null;
        $hadIsNpc = array_key_exists('IS_NPC', $GLOBALS);

        $GLOBALS['DIALECTIC_NAME'] = 'The Narrator';
        $GLOBALS['IS_NPC'] = false;

        try {
            $this->assertTrue(dialecticActionCatalogRowIsAvailableInCurrentMode([
                'available_to_npc' => false,
                'available_to_followers' => false,
                'available_to_narrator' => true,
            ]));

            $this->assertFalse(dialecticActionCatalogRowIsAvailableInCurrentMode([
                'available_to_npc' => true,
                'available_to_followers' => true,
                'available_to_narrator' => false,
            ]));
        } finally {
            if ($hadDialecticName) {
                $GLOBALS['DIALECTIC_NAME'] = $previousDialecticName;
            } else {
                unset($GLOBALS['DIALECTIC_NAME']);
            }

            if ($hadIsNpc) {
                $GLOBALS['IS_NPC'] = $previousIsNpc;
            } else {
                unset($GLOBALS['IS_NPC']);
            }
        }
    }
}
