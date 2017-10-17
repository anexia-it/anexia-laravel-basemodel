<?php

namespace Anexia\BaseModel\Tests\Feature\Controllers;

use Anexia\BaseModel\Tests\DbTestCase;
use Illuminate\Database\Eloquent\Model;

/**
 * First Extension of this class takes care of all login tests
 *
 * Class AuthenticatedControllerTestCase
 * @package Tests\Feature\Controllers
 */
abstract class RestControllerTestCase extends DbTestCase
{
    /**
     * Login as therapist on each test
     */
    public function setUp()
    {
        parent::setUp();

        $this->login();
    }

    /**
     * Set the authenticated user for tests
     *
     * @param Model|int $user
     *
     * e.g.:
     *
     * public function login($user = 1)
     * {
     *     if (is_int($user)) {
     *         $user = User::find($user);
     *     }
     *
     *     $this->actingAs($user, 'api');
     *
     *     request()->merge(['user' => $user]);
     *     $this->currentUser = $user;
     * }
     */
    abstract public function login($user);

    /**
     * @param array $responseBody
     * @param int $total
     * @param int $page; default 1
     * @param int $page; default 10
     */
    public function runPaginationTests($responseBody, $total, $page = 1, $perPage = 10)
    {
        $this->assertArrayHasKey('current_page', $responseBody);
        $this->assertEquals($page, $responseBody['current_page']);
        $this->assertArrayHasKey('per_page', $responseBody);
        $this->assertEquals($perPage, $responseBody['per_page']);
        $this->assertArrayHasKey('total', $responseBody);
        $this->assertEquals($total, $responseBody['total']);
        $lastPage = (int) ceil(($total / $perPage));
        $from = ($perPage * ($page - 1)) + 1;
        $to = min($total, ($from + $perPage - 1));
        $this->assertArrayHasKey('last_page', $responseBody);
        $this->assertEquals($lastPage, $responseBody['last_page']);
        $this->assertArrayHasKey('from', $responseBody);
        $this->assertEquals($from, $responseBody['from']);
        $this->assertArrayHasKey('to', $responseBody);
        $this->assertEquals($to, $responseBody['to']);
        $this->assertArrayHasKey('prev_page_url', $responseBody);
        $this->assertArrayHasKey('next_page_url', $responseBody);

        if ($page == 1) {
            $this->assertNull($responseBody['prev_page_url']);
        } else {
            $this->assertNotNull($responseBody['prev_page_url']);
        }

        if ($page == $lastPage) {
            $this->assertNull($responseBody['next_page_url']);
        } else {
            $this->assertNotNull($responseBody['next_page_url']);
        }
    }
}