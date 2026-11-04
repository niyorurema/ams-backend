<?php

use App\Controllers\Api\UsersController;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Services;

/**
 * @internal
 */
final class UserInputValidationTest extends CIUnitTestCase
{
    public function testAcceptsBooleanFalseForSuperAdminStatus(): void
    {
        $controller = new UsersController();
        $controller->initController(
            Services::incomingrequest(new App(), false),
            Services::response(new App(), false),
            Services::logger()
        );
        $validator = new \ReflectionMethod(UsersController::class, 'validateUserInput');

        $result = $validator->invoke($controller, ['is_super_admin' => false], false);

        $this->assertNull($result);
    }
}
