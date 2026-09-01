<?php

namespace Tests\Vatssa;

/**
 * VATSSA: skip an upstream test that asserts the per-area role model.
 *
 * ## Why these tests cannot pass here, and must not be deleted
 *
 * Upstream grants roles per area, because VATSCA has per-area staff. VATSSA has
 * one division and one set of people, so `UserPolicy` refuses an area on every
 * grant and `RoleAssignment` throws on one. Several upstream tests exist
 * precisely to prove area grants work, and they now throw instead.
 *
 * Deleting them would be the easy fix and the wrong one. They are upstream's
 * record of upstream's behaviour: if a future release changes how areas work,
 * the diff should land on a test that still exists rather than on a gap nobody
 * remembers. A skip with a stated reason survives the merge and shows up in
 * every CI run as a line of text; a deletion shows up as nothing.
 *
 * ## This is not a way to silence a failure
 *
 * Only for tests that contradict a DELIBERATE fork decision. A test failing
 * because we broke something is a bug, and reaching for this instead of fixing
 * it is how a suite stops meaning anything. If you cannot name the decision in
 * one sentence, it is not one of these.
 */
trait UpstreamRoleModel
{
    protected function skipPerAreaRoles(string $what): void
    {
        $this->markTestSkipped(
            "VATSSA grants every role globally, so {$what} cannot be exercised here. "
            . 'Kept rather than deleted so a future upstream change to area scoping '
            . 'still lands on a test. See tests/Vatssa/UpstreamRoleModel.php.'
        );
    }
}
