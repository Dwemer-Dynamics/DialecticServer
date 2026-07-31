<?php

function itt($file, $hints)
{
    return dialecticIttOpenAiCompatible('openrouter', strval($file), strval($hints), true);
}
