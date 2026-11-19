# Training additions

## For staff

**Theory mark.** VATSSA's theory exam runs in Moodle, so staff mark a training's theory as passed in Control Center. The button sits beside the training's title buttons. A badge shows next to the state, on the training page and on Open requests. The mark is written to the training's timeline.

**Requires attention** (amber) and **Stalled** (red). Two flags staff set or clear on an open training, beside the theory mark. Each change is written to the timeline as a staff comment, which students do not see. A training that closes loses both flags.

**Who:** staff with `training.update` in the training's area: Pipeline Coordinator, ATC Training Manager, admin. Not mentors, not students. Open trainings only.

**Removed from upstream:**
- The student's "Mark pre-training as completed" button. It gated nothing and read as a theory pass.
- The **Theoretical Exam Access** request type, because the exam runs in Moodle.

## How it works

The logic is in added files (`VatssaTheoryController`, `VatssaTrainingFlagController`, the `VatssaTrainingFlags` service, and the gates and hooks in `VatssaServiceProvider`). The upstream training views only include VATSSA partials. Columns on `trainings`: `theory_completed` (yes or no), `attention_at` and `stalled_at` (when each flag was set). Details and detectors: [`PATCHES.md`](../PATCHES.md). Tests: `VatssaTheoryCompletedTest`, `VatssaTrainingAttentionTest`, `VatssaTrainingStalledTest`.

---
*VATSSA Control Center documentation © 2026 Daniël Schoonraad*
