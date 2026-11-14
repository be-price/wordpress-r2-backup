<?php
declare(strict_types=1);

namespace WordPressR2Backup {
    function wp_unslash(string $value): string
    {
        return stripslashes($value);
    }
}

namespace WordPressR2Backup\Tests {
    use InvalidArgumentException;

    use PHPUnit\Framework\TestCase;
    use WordPressR2Backup\WordPressAdmin;

    final class WordPressAdminTest extends TestCase
    {
        public static function commands(): array
        {
            return [
                ['register_keys', ['recipient_1' => 'age1first', 'recipient_2' => 'age1second']],
                ['issue_challenge', []],
                ['download_challenge', ['slot' => '2']],
                ['confirm_challenge', ['slot' => '1', 'challenge_id' => str_repeat('a', 64), 'code' => str_repeat('b', 64)]],
                ['start', ['request_id' => str_repeat('c', 64)]],
                ['resume', ['operation_id' => str_repeat('d', 64)]],
            ];
        }

        /** @dataProvider commands */
        public function testAcceptsOnlyTheFieldsOfEachCommand(string $command, array $fields): void
        {
            $post = ['action' => 'wrb_' . $command, '_wrb_nonce' => 'nonce'] + $fields;
            self::assertSame($post, WordPressAdmin::parseCommand($command, $post));
        }

        public static function malformedRequests(): array
        {
            $start = ['action' => 'wrb_start', '_wrb_nonce' => 'nonce', 'request_id' => 'request'];
            return [
                ['unknown', $start],
                ['start', $start + ['profile' => '/arbitrary/path']],
                ['start', array_replace($start, ['request_id' => ['request']])],
                ['start', array_replace($start, ['request_id' => 7])],
                ['start', array_replace($start, ['request_id' => ''])],
                ['start', array_replace($start, ['request_id' => str_repeat('x', 129)])],
                ['start', array_replace($start, ['request_id' => "nul\0byte"])],
                ['start', array_replace($start, ['action' => 'wrb_resume'])],
                ['start', array_replace($start, ['_wrb_nonce' => ['nonce']])],
                ['start', ['action' => 'wrb_start', '_wrb_nonce' => 'nonce']],
                ['download_challenge', ['action' => 'wrb_download_challenge', '_wrb_nonce' => 'nonce', 'slot' => '3']],
                ['issue_challenge', ['action' => 'wrb_issue_challenge', '_wrb_nonce' => 'nonce', 'slot' => '1']],
            ];
        }

        /** @dataProvider malformedRequests */
        public function testRejectsUnknownMissingNonScalarOrOversizedInput(string $command, array $post): void
        {
            $this->expectException(InvalidArgumentException::class);
            WordPressAdmin::parseCommand($command, $post);
        }

        public function testUsesUnslashedInputWithoutSilentlyTrimmingValues(): void
        {
            $input = WordPressAdmin::parseCommand('confirm_challenge', [
                'action' => 'wrb_confirm_challenge', '_wrb_nonce' => 'nonce', 'slot' => '1',
                'challenge_id' => 'challenge', 'code' => " a\\'b ",
            ]);
            self::assertSame(" a'b ", $input['code']);
        }
    }
}
