<?php declare(strict_types=1);

require_once __DIR__.DIRECTORY_SEPARATOR.'DatabaseTestCase.php';

final class NarratorPromptActionTest extends DatabaseTestCase
{
    public function testNarratorInputTextHidesDisabledNarratorPrivateActionsByDefault(): void
    {
        require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'phpunit.class.php';

        $db = new sql();
        $GLOBALS['db'] = $db;

        $GLOBALS['PLAYER_NAME'] = 'RANGROO';
        $GLOBALS['DIALECTIC_NAME'] = 'The Narrator';
        $GLOBALS['IS_NPC'] = false;
        $GLOBALS['DIRECT_NARRATOR_DIALOGUE'] = true;
        $GLOBALS['FUNCTIONS_ARE_ENABLED'] = true;
        $GLOBALS['EMOTEMOODS'] = 'neutral,assertive';
        $GLOBALS['LANG_LLM_XTTS'] = false;
        $GLOBALS['TTSFUNCTION'] = '';
        $GLOBALS['INLINE_NARRATION_MODE'] = 'disabled';
        $GLOBALS['use_emotions_expression'] = false;
        $GLOBALS['FEATURES']['MISC']['JSON_DIALOGUE_FORMAT_REORDER'] = false;
        $GLOBALS['gameRequest'] = ['narrator_inputtext', 100, 200, 'give rangroo 100 caps'];
        $gameRequest = $GLOBALS['gameRequest'];

        require __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'prompt.includes.php';

        $this->assertStringContainsString('Check #ACTIONS section', $PROMPTS['narrator_inputtext']['cue'][0] ?? '');

        $GLOBALS['FUNCTIONS_ARE_ENABLED'] = false;
        require __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'functions'.DIRECTORY_SEPARATOR.'json_response.php';

        $this->assertArrayHasKey('action', $GLOBALS['responseTemplate']);
        $this->assertIsString($GLOBALS['responseTemplate']['action']);
        $this->assertStringContainsString('AVAILABLE ACTION: Talk', $GLOBALS['PROMPT_ACTIONS_LIST']);

        $GLOBALS['FUNC_LIST'] = ['Talk'];
        $GLOBALS['PROMPT_ACTIONS_LIST'] = "\n<available_actions_list>\nAVAILABLE ACTION: Talk\n</available_actions_list>";
        $GLOBALS['responseTemplate']['action'] = 'Talk';

        $this->assertTrue(function_exists('dialecticRefreshJsonResponseState'));
        dialecticRefreshJsonResponseState();

        $this->assertStringContainsString('AVAILABLE ACTION: Talk', $GLOBALS['PROMPT_ACTIONS_LIST']);

        $db->close();
        unset($GLOBALS['db']);
    }

    public function testNarratorCheatmodeKeepsNarratorActionsEnabled(): void
    {
        require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'phpunit.class.php';

        $db = new sql();
        $GLOBALS['db'] = $db;

        $GLOBALS['PLAYER_NAME'] = 'RANGROO';
        $GLOBALS['DIALECTIC_NAME'] = 'The Narrator';
        $GLOBALS['IS_NPC'] = false;
        $GLOBALS['DIRECT_NARRATOR_DIALOGUE'] = true;
        $GLOBALS['FUNCTIONS_ARE_ENABLED'] = true;
        $GLOBALS['EMOTEMOODS'] = 'neutral,assertive';
        $GLOBALS['LANG_LLM_XTTS'] = false;
        $GLOBALS['TTSFUNCTION'] = '';
        $GLOBALS['INLINE_NARRATION_MODE'] = 'disabled';
        $GLOBALS['use_emotions_expression'] = false;
        $GLOBALS['FEATURES']['MISC']['JSON_DIALOGUE_FORMAT_REORDER'] = false;
        $GLOBALS['gameRequest'] = ['cheatmode', 100, 200, '<pick up the nearby wrench>'];
        $db->execQuery("UPDATE public.core_action SET is_activated = TRUE WHERE code_name = 'PickupItem'");

        require __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'functions'.DIRECTORY_SEPARATOR.'json_response.php';

        $this->assertStringContainsString('Pickup_Item', $GLOBALS['responseTemplate']['action'] ?? '');
        $this->assertStringContainsString('AVAILABLE ACTION: Pickup_Item', $GLOBALS['PROMPT_ACTIONS_LIST'] ?? '');

        $db->close();
        unset($GLOBALS['db']);
    }

    public function testNarratorPickupItemDisplayActionNameResolvesToCodeNameAndPayload(): void
    {
        require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'phpunit.class.php';

        $db = new sql();
        $GLOBALS['db'] = $db;

        $GLOBALS['PLAYER_NAME'] = 'RANGROO';
        $GLOBALS['DIALECTIC_NAME'] = 'The Narrator';
        $GLOBALS['IS_NPC'] = false;
        $GLOBALS['DIRECT_NARRATOR_DIALOGUE'] = true;
        $GLOBALS['FUNCTIONS_ARE_ENABLED'] = true;
        $GLOBALS['EMOTEMOODS'] = 'neutral,assertive';
        $GLOBALS['LANG_LLM_XTTS'] = false;
        $GLOBALS['TTSFUNCTION'] = '';
        $GLOBALS['INLINE_NARRATION_MODE'] = 'disabled';
        $GLOBALS['use_emotions_expression'] = false;
        $GLOBALS['FEATURES']['MISC']['JSON_DIALOGUE_FORMAT_REORDER'] = false;
        $GLOBALS['gameRequest'] = ['narrator_inputtext', 100, 200, 'pick up the nearby wrench'];
        $db->execQuery("UPDATE public.core_action SET is_activated = TRUE WHERE code_name = 'PickupItem'");

        require __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'functions'.DIRECTORY_SEPARATOR.'json_response.php';

        $executionContext = buildFunctionExecutionContextFromResponse([
            'action' => 'Pickup_Item',
            'target' => '',
            'item' => '0xFF000123:Wrench',
            'amount' => 1,
        ]);

        $this->assertTrue($executionContext['function_found']);
        $this->assertSame('PickupItem', $executionContext['function_code_name']);
        $this->assertIsArray($executionContext['parameter_value']);
        $this->assertSame('0xFF000123:Wrench', $executionContext['parameter_value']['item'] ?? null);
        $this->assertSame(1, $executionContext['parameter_value']['amount'] ?? null);

        $db->close();
        unset($GLOBALS['db']);
    }

    public function testNarratorGiveCapsDisplayActionNameResolvesToCodeNameAndPayload(): void
    {
        require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'phpunit.class.php';

        $db = new sql();
        $GLOBALS['db'] = $db;

        $GLOBALS['PLAYER_NAME'] = 'RANGROO';
        $GLOBALS['DIALECTIC_NAME'] = 'The Narrator';
        $GLOBALS['IS_NPC'] = false;
        $GLOBALS['DIRECT_NARRATOR_DIALOGUE'] = true;
        $GLOBALS['FUNCTIONS_ARE_ENABLED'] = true;
        $GLOBALS['EMOTEMOODS'] = 'neutral,assertive';
        $GLOBALS['LANG_LLM_XTTS'] = false;
        $GLOBALS['TTSFUNCTION'] = '';
        $GLOBALS['INLINE_NARRATION_MODE'] = 'disabled';
        $GLOBALS['use_emotions_expression'] = false;
        $GLOBALS['FEATURES']['MISC']['JSON_DIALOGUE_FORMAT_REORDER'] = false;
        $GLOBALS['gameRequest'] = ['narrator_inputtext', 100, 200, 'give RANGROO 1000 caps'];
        $db->execQuery("UPDATE public.core_action SET is_activated = TRUE WHERE code_name = 'GiveCapsTo'");

        require __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'functions'.DIRECTORY_SEPARATOR.'json_response.php';

        $executionContext = buildFunctionExecutionContextFromResponse([
            'action' => 'Give_Caps_To',
            'target' => 'RANGROO',
            'item' => '',
            'amount' => 1000,
        ]);

        $this->assertTrue($executionContext['function_found']);
        $this->assertSame('GiveCapsTo', $executionContext['function_code_name']);
        $this->assertIsArray($executionContext['parameter_value']);
        $this->assertSame('RANGROO', $executionContext['parameter_value']['target'] ?? null);
        $this->assertSame(1000, $executionContext['parameter_value']['amount'] ?? null);

        $db->close();
        unset($GLOBALS['db']);
    }
}
