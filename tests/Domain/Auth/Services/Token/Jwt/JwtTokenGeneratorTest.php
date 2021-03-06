<?php

namespace BackToWin\Domain\Auth\Services\Token\Jwt;

use BackToWin\Bootstrap\Config;
use BackToWin\Domain\Auth\Services\Token\TokenGenerator;
use BackToWin\Framework\Uuid\Uuid;
use BackToWin\Testing\Traits\UsesContainer;
use Interop\Container\ContainerInterface;
use Lcobucci\JWT\Parser;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use PHPUnit\Framework\TestCase;

class JwtTokenGeneratorTest extends TestCase
{
    use UsesContainer;

    /** @var  ContainerInterface */
    private $container;
    /** @var  TokenGenerator */
    private $generator;
    /** @var  Config */
    private $config;

    public function setUp()
    {
        $this->container = $this->createContainer();
        $this->config = $this->container->get(Config::class);
        $this->config->set('auth.token.driver', 'jwt');
        $this->generator = $this->container->get(TokenGenerator::class);
    }

    public function test_interface_is_bound()
    {
        $this->assertInstanceOf(TokenGenerator::class, $this->generator);
    }

    public function test_generate_returns_a_valid_encoded_jwt_token_string()
    {
        $token = $this->generator->generate(
            new Uuid('058b1dc8-d168-4bab-aa4a-ffeed90c5435'),
            (new \DateTimeImmutable())->add(new \DateInterval('P1D'))
        );
        
        $parsed = (new Parser())->parse($token);

        $this->assertEquals('058b1dc8-d168-4bab-aa4a-ffeed90c5435', $parsed->getClaim('user_id'));
        $this->assertFalse($parsed->isExpired());
        $this->assertEquals('JWT', $parsed->getHeader('typ'));
        $this->assertEquals('HS256', $parsed->getHeader('alg'));
        $this->assertTrue($parsed->verify(new Sha256(), $this->config->get('auth.jwt.secret')));
    }
}
