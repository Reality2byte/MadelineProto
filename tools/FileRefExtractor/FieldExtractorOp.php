<?php

declare(strict_types=1);

/**
 * This file is part of MadelineProto.
 * MadelineProto is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * MadelineProto is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General Public License for more details.
 * You should have received a copy of the GNU General Public License along with MadelineProto.
 * If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2025 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/AGPL-3.0 AGPLv3
 * @link https://docs.madelineproto.xyz MadelineProto documentation
 */

namespace danog\MadelineProto\FileRefExtractor;

use AssertionError;
use danog\MadelineProto\FileRefExtractor\Ops\ExtractFromHereOp;
use Webmozart\Assert\Assert;

abstract readonly class FieldExtractorOp implements TypedOp
{
    final public function __construct(
        /** @var list<list{0: string, 1: string, 2?: TypedOp|'*'|null}> */
        public array $path,
    ) {
        foreach ($path as $elem) {
            if (\count($elem) !== 2 && \count($elem) !== 3) {
                throw new \InvalidArgumentException('Invalid path part: ' . json_encode($path));
            }
            if (isset($elem[2])
                && !$elem[2] instanceof TypedOp
                && $elem[2] !== true
                && $elem[2] !== '*'
            ) {
                throw new \InvalidArgumentException('Invalid path part: ' . json_encode($path));
            }
        }
    }

    final public function getType(TLContext $tl): string
    {
        $path = $this;
        if ($path instanceof ExtractFromHereOp) {
            Assert::eq($tl->position, $path->path[0][0], "getTypeAtPosition: Current constructor {$tl->position} does not match expected constructor {$path->path[0][0]}");
        }
        $path = $path->path;
        $idx = 0;
        $type = null;
        $realType = null;
        do {
            [$requiredConstructor, $requiredParam] = $path[$idx];
            $expectFlag = \array_key_exists(2, $path[$idx]);
            $alternativeFlagType = $path[$idx][2] ?? null;

            if ($realType !== null) {
                $consOfType = $tl->tl->getConstructorsOfType($realType, true);
                $methodsOfType = $tl->tl->getMethodsOfType($realType, true);

                if (isset($consOfType[$requiredConstructor])) {
                    // OK
                } elseif (isset($methodsOfType[$requiredConstructor])) {
                    // OK
                } else {
                    throw new AssertionError("{$requiredConstructor} is NOT a constructor of type $type, path");
                }
            }
            $constructor = $tl->tl->tl->getConstructors()->findByPredicate($requiredConstructor);
            if ($constructor === false) {
                $constructor = $tl->tl->tl->getMethods()->findByMethod($requiredConstructor);
            }
            Assert::notFalse($constructor, "Constructor or method not found for path");

            $type = null;
            if ($requiredParam === '') {
                Assert::true(isset($constructor['method']), "Expected method at position $idx in path ".json_encode($path));
                $type = $constructor['type'];
                $realType = $constructor['subtype'] ?? $constructor['type'];
                Assert::false($expectFlag);
                continue;
            }
            $n = $constructor['predicate'] ?? $constructor['method'];
            foreach ($constructor['params'] as $param) {
                if ($param['name'] === $requiredParam) {
                    $type = isset($param['subtype']) ? "Vector<{$param['subtype']}>" : $param['type'];
                    $realType = $param['subtype'] ?? $param['type'];
                    $isFlag = isset($param['pow']);
                    if ($isFlag !== $expectFlag) {
                        $isFlag = $isFlag ? 'flag' : 'no flag';
                        $expectFlag = $expectFlag ? 'flag' : 'no flag';
                        throw new AssertionError("Expected $expectFlag, got $isFlag for $requiredConstructor.$requiredParam at position $idx in path ".json_encode($path));
                    }
                    if ($isFlag) {
                        if ($alternativeFlagType instanceof TypedOp) {
                            Assert::eq($type, $alternativeFlagType->getType($tl), "Expected flag type at position $idx in path ".json_encode($path));
                        } elseif ($alternativeFlagType === true) {
                            Assert::eq($type, 'true');
                        }
                    }
                    break;
                }
            }
            Assert::notNull($type, "Parameter {$requiredParam} not found in constructor or method $n");
            Assert::notNull($realType, "Parameter {$requiredParam} not found in constructor or method $n");
        } while (++$idx < \count($path));

        return $type;
    }

}
