# Test fixtures

`6847855100000123120251604C.DAT` and `.TXT` are a single-employee 1604-C (v7.4, TY2025) sample and the
BIR validator report for it ("No Errors Encountered"). They come from the public repository
github.com/ReyJrFerrer/BIR-dat (folder `res/`). The TIN 684785510 and employee data are synthetic test values.

The repository records the original DAT SHA-256 as
`59892d6a782fb46aa44060ec9ca74db5ae3a3862eb247513908b8bb6a519c788` (CRLF line endings). Git checkouts may
convert line endings; this copy was restored to CRLF and matches that hash. `tests/run.php` checks that the
1604-C writer reproduces these exact bytes.
