$src = "C:\Users\jprocasky\OneDrive\z-Github\breathermae\Breathermae Voice Regulation\data\raw"
$dst = "C:\BioVoice\test_sessions"

function Copy-Hydrated($fromGlob, $toPath) {
    $matches = @(Get-Item -Path $fromGlob -ErrorAction Stop)
    if ($matches.Count -ne 1) {
        throw "Expected 1 file for '$fromGlob', found $($matches.Count)"
    }
    $f = $matches[0]
    # Force OneDrive to hydrate the real bytes
    $null = [System.IO.File]::ReadAllBytes($f.FullName)
    $dir = Split-Path $toPath -Parent
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    Copy-Item -LiteralPath $f.FullName -Destination $toPath -Force
    $out = Get-Item $toPath
    Write-Host ("{0,-70} {1,10} bytes -> {2}" -f $f.Name, $out.Length, $toPath)
}

$pairs = @(
    @("Frank Session 1\*Step 1*",  "s1\silence_pre.m4a"),
    @("Frank Session 1\*Step 2*",  "s1\phonation_1.m4a"),
    @("Frank Session 1\*Step 3A*", "s1\count_natural.m4a"),
    @("Frank Session 1\*Step 3B*", "s1\count_slow.m4a"),
    @("Frank Session 1\*Step 4*",  "s1\reading.m4a"),
    @("Frank Session 1\*Step 5*",  "s1\silence_post.m4a"),

    @("Frank Session 2\*Step 1*",  "s2\silence_pre.m4a"),
    @("Frank Session 2\*Step 2*",  "s2\phonation_1.m4a"),
    @("Frank Session 2\*Step 3A*", "s2\count_natural.m4a"),
    @("Frank Session 2\*Step 3B*", "s2\count_slow.m4a"),
    @("Frank Session 2\*Step 4*",  "s2\reading.m4a"),
    @("Frank Session 2\*Step 5*",  "s2\silence_post.m4a"),

    @("Frank Session 3\*Step 1*",  "s3\silence_pre.m4a"),
    @("Frank Session 3\*Step 2*",  "s3\phonation_1.m4a"),
    @("Frank Session 3\*Step 3A*", "s3\count_natural.m4a"),
    @("Frank Session 3\*Step 3B*", "s3\count_slow.m4a"),
    @("Frank Session 3\*Step 4*",  "s3\reading.m4a"),
    @("Frank Session 3\*Step 5*",  "s3\silence_post.m4a"),

    @("Frank Comparison Session 1\*Step 1*",  "comparison1\silence_pre.m4a"),
    @("Frank Comparison Session 1\*Step 2*",  "comparison1\phonation_1.m4a"),
    @("Frank Comparison Session 1\*Step 3A*", "comparison1\count_natural.m4a"),
    @("Frank Comparison Session 1\*Step 3B*", "comparison1\count_slow.m4a"),
    @("Frank Comparison Session 1\*Step 4*",  "comparison1\reading.m4a"),
    @("Frank Comparison Session 1\*Step 5*",  "comparison1\silence_post.m4a")
)

foreach ($p in $pairs) {
    Copy-Hydrated (Join-Path $src $p[0]) (Join-Path $dst $p[1])
}

Write-Host "`nDone. Checking s1:"
Get-ChildItem "$dst\s1" | Format-Table Name, Length