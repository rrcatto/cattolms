"""Convert one repository from F3 DB\\SQL to Doctrine DBAL.

Mechanical part only: the constructor type, the fetch calls and the binding keys. Anything it cannot
classify with certainty is reported and left alone, because a silent partial conversion is worse
than none: the file still compiles and fails at runtime.
"""
import io, re, sys

def convert(path):
    s = io.open(path, encoding='utf-8').read()
    report = []

    s2 = s.replace('use DB\\SQL;', 'use Doctrine\\DBAL\\Connection;')
    s2 = s2.replace('private readonly SQL $db', 'private readonly Connection $db')
    if s2 == s:
        report.append('no DB\\SQL constructor')
    s = s2

    s, n_lit = re.subn(r"':([a-zA-Z_][a-zA-Z0-9_]*)' =>", r"'\1' =>", s)
    s, n_dyn = re.subn(r"\[':([a-zA-Z_][a-zA-Z0-9_]*)'\]", r"['\1']", s)

    # Look far enough past the opening delimiter to read the SQL verb, including heredocs.
    def call(m):
        head = m.group(2)
        verb = re.search(r"\b(SELECT|WITH|INSERT|UPDATE|DELETE)\b", head, re.I)
        if not verb:
            return m.group(0)
        v = verb.group(1).upper()
        method = 'fetchAllAssociative' if v in ('SELECT', 'WITH') else 'executeStatement'
        return f'{m.group(1)}$this->db->{method}({head}'

    s, n_calls = re.subn(r'(\s*)\$this->db->exec\((\s*(?:["\']|<<<)[^\n]{0,120})', call, s)
    # F3 transaction verbs differ from DBAL's.
    s = s.replace('$this->db->begin()', '$this->db->beginTransaction()')
    s = s.replace('$this->db->rollback()', '$this->db->rollBack()')

    # A converted call whose SQL runs past the sampled window keeps its exec(); report it.
    for m in re.finditer(r'\$this->db->(fetchAllAssociative|executeStatement)\((.{0,400})', s, re.S):
        if re.search(r'\bRETURNING\b', m.group(2)[:400], re.I) and m.group(1) == 'executeStatement':
            report.append('an INSERT ... RETURNING became executeStatement - check by hand')

    leftover = s.count('$this->db->exec(')
    if leftover:
        report.append(f'{leftover} exec() call(s) unclassified - by hand')

    io.open(path, 'w', encoding='utf-8').write(s)
    return n_lit + n_dyn, n_calls, report

for path in sys.argv[1:]:
    keys, calls, report = convert(path)
    print(f'  {path.split("/")[-1]:34} bindings={keys:<4} calls={calls:<4} {"; ".join(report) or "clean"}')
