# Emorfiq — Scheduler and Mutex design

Hiring test from Emorfiq: design the class interfaces for a Scheduler and a Mutex so the
Laravel implementation can be swapped at any time (crunz is the named alternative) and the
system is not bound to Laravel. Laravel's own mutex is unreliable, so the mutex is our own.
The design goes down to input parameters and their types. The current implementation must
schedule and run crons/jobs at a given time; crontab notation is enough.

The answer has to be one Jan stands behind and can defend in a follow-up call: why each
interface, which alternatives existed, and what each choice costs.

The original assignment (Czech) is in `ASSIGNMENT.md`.
