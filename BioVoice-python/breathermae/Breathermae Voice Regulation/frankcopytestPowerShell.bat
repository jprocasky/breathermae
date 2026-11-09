$src = "C:\Users\jprocasky\OneDrive\z-Github\breathermae\Breathermae Voice Regulation\data\raw"
$dst = "C:\BioVoice\test_sessions"

# Force OneDrive to fully download each file, then copy
function Copy-Hydrated($fromGlob, $toPath) {
  $f = Get-Item $fromGlob
  # Touch/read to force hydration
  $null = [System.IO.File]::ReadAllBytes($f.FullName)
  Copy-Item -LiteralPath $f.FullName -Destination $toPath -Force
  Write-Host "$($f.Name)  $($f.Length) bytes  ->  $toPath"
}

Copy-Hydrated "$src\Frank Session 1\*Step 1*" "$dst\s1\silence_pre.m4a"
Copy-Hydrated "$src\Frank Session 1\*Step 2*" "$dst\s1\phonation_1.m4a"
Copy-Hydrated "$src\Frank Session 1\*Step 3A*" "$dst\s1\count_natural.m4a"
Copy-Hydrated "$src\Frank Session 1\*Step 3B*" "$dst\s1\count_slow.m4a"
Copy-Hydrated "$src\Frank Session 1\*Step 4*"  "$dst\s1\reading.m4a"
Copy-Hydrated "$src\Frank Session 1\*Step 5*"  "$dst\s1\silence_post.m4a"