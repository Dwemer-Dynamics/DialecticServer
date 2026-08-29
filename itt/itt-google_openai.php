<?php

function itt($file, $hints)
{
    return dialecticIttOpenAiCompatible('google_openai', strval($file), strval($hints));
}
