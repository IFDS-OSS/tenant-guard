<?php

namespace Ifds\TenantGuard\Tests\Feature;

use Illuminate\Support\Facades\Validator;
use Ifds\TenantGuard\Facades\TenantGuard;
use Ifds\TenantGuard\Rules\TenantOwned;
use Ifds\TenantGuard\Rules\UniqueForTenant;
use Ifds\TenantGuard\Tests\TestCase;
use Workbench\App\Models\Post;

/**
 * Layer 5 - `exists:` and `unique:` are not tenant-aware, and both leak.
 */
class ValidationRuleTest extends TestCase
{
    public function test_tenant_owned_passes_for_an_owned_row(): void
    {
        $post = $this->withinTenant($this->acme, fn () => Post::create(['title' => 'A', 'slug' => 'a']));

        TenantGuard::set($this->acme);

        $validator = Validator::make(['post_id' => $post->id], [
            'post_id' => [new TenantOwned(Post::class)],
        ]);

        $this->assertTrue($validator->passes());
    }

    public function test_tenant_owned_fails_for_another_tenants_row(): void
    {
        $foreign = $this->withinTenant($this->globex, fn () => Post::create(['title' => 'B', 'slug' => 'b']));

        TenantGuard::set($this->acme);

        $validator = Validator::make(['post_id' => $foreign->id], [
            'post_id' => [new TenantOwned(Post::class)],
        ]);

        $this->assertFalse($validator->passes());
    }

    public function test_the_plain_exists_rule_would_have_leaked(): void
    {
        $foreign = $this->withinTenant($this->globex, fn () => Post::create(['title' => 'C', 'slug' => 'c']));

        TenantGuard::set($this->acme);

        // Documents the vulnerability the rule exists to close.
        $leaky = Validator::make(['post_id' => $foreign->id], ['post_id' => 'exists:posts,id']);
        $guarded = Validator::make(['post_id' => $foreign->id], ['post_id' => [new TenantOwned(Post::class)]]);

        $this->assertTrue($leaky->passes(), 'exists: confirms another tenant\'s row');
        $this->assertFalse($guarded->passes(), 'TenantOwned does not');
    }

    public function test_tenant_owned_works_against_a_raw_table_name(): void
    {
        $post = $this->withinTenant($this->acme, fn () => Post::create(['title' => 'D', 'slug' => 'd']));
        $foreign = $this->withinTenant($this->globex, fn () => Post::create(['title' => 'E', 'slug' => 'e']));

        TenantGuard::set($this->acme);

        $this->assertTrue(Validator::make(['id' => $post->id], ['id' => [new TenantOwned('posts')]])->passes());
        $this->assertFalse(Validator::make(['id' => $foreign->id], ['id' => [new TenantOwned('posts')]])->passes());
    }

    public function test_tenant_owned_validates_every_value_in_an_array(): void
    {
        $mine = $this->withinTenant($this->acme, fn () => Post::create(['title' => 'F', 'slug' => 'f']));
        $theirs = $this->withinTenant($this->globex, fn () => Post::create(['title' => 'G', 'slug' => 'g']));

        TenantGuard::set($this->acme);

        $this->assertFalse(
            Validator::make(['ids' => [$mine->id, $theirs->id]], ['ids' => [new TenantOwned(Post::class)]])->passes(),
            'one foreign id in the list must fail the whole rule'
        );
    }

    public function test_tenant_owned_fails_closed_without_a_context(): void
    {
        $post = $this->withinTenant($this->acme, fn () => Post::create(['title' => 'H', 'slug' => 'h']));

        $this->actingWithoutTenant();

        $this->assertFalse(
            Validator::make(['post_id' => $post->id], ['post_id' => [new TenantOwned(Post::class)]])->passes()
        );
    }

    public function test_tenant_owned_accepts_an_extra_constraint(): void
    {
        $draft = $this->withinTenant(
            $this->acme,
            fn () => Post::create(['title' => 'I', 'slug' => 'i', 'published_at' => null])
        );

        TenantGuard::set($this->acme);

        $rule = (new TenantOwned(Post::class))->where(fn ($q) => $q->whereNotNull('published_at'));

        $this->assertFalse(Validator::make(['post_id' => $draft->id], ['post_id' => [$rule]])->passes());
    }

    public function test_unique_for_tenant_allows_the_same_slug_in_another_tenant(): void
    {
        $this->withinTenant($this->acme, fn () => Post::create(['title' => 'Same', 'slug' => 'same']));

        TenantGuard::set($this->globex);

        $this->assertTrue(
            Validator::make(['slug' => 'same'], ['slug' => [new UniqueForTenant(Post::class)]])->passes(),
            'slugs only need to be unique within a tenant'
        );
    }

    public function test_unique_for_tenant_rejects_a_duplicate_within_the_tenant(): void
    {
        $this->withinTenant($this->acme, fn () => Post::create(['title' => 'Same', 'slug' => 'same']));

        TenantGuard::set($this->acme);

        $this->assertFalse(
            Validator::make(['slug' => 'same'], ['slug' => [new UniqueForTenant(Post::class)]])->passes()
        );
    }

    public function test_unique_for_tenant_can_ignore_the_record_being_updated(): void
    {
        $post = $this->withinTenant($this->acme, fn () => Post::create(['title' => 'Same', 'slug' => 'same']));

        TenantGuard::set($this->acme);

        $rule = (new UniqueForTenant(Post::class))->ignore($post);

        $this->assertTrue(Validator::make(['slug' => 'same'], ['slug' => [$rule]])->passes());
    }

    public function test_unique_for_tenant_uses_a_raw_table(): void
    {
        $this->withinTenant($this->acme, fn () => Post::create(['title' => 'Same', 'slug' => 'same']));

        TenantGuard::set($this->acme);
        $this->assertFalse(Validator::make(['slug' => 'same'], ['slug' => [new UniqueForTenant('posts')]])->passes());

        TenantGuard::set($this->globex);
        $this->assertTrue(Validator::make(['slug' => 'same'], ['slug' => [new UniqueForTenant('posts')]])->passes());
    }

    public function test_the_failure_message_is_translated(): void
    {
        $foreign = $this->withinTenant($this->globex, fn () => Post::create(['title' => 'J', 'slug' => 'j']));

        TenantGuard::set($this->acme);

        $validator = Validator::make(['post_id' => $foreign->id], ['post_id' => [new TenantOwned(Post::class)]]);

        $this->assertSame(
            'The selected post id is invalid.',
            $validator->errors()->first('post_id')
        );
    }
}
