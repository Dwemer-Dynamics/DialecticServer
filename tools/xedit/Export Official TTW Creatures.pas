unit UserScript;

{
  Exports authoritative winning CREA records from the official TTW masters.
  Run through the TTW Mod Organizer 2 instance with:
    FNVEdit -autoload -script:"Export Official TTW Creatures" -autoexit

  The raw CSV is an inventory input and is intentionally written under tmp/.
}

const
  OutputPath = 'tmp\fallout_official_crea_xedit.csv';
  OfficialFiles = '|falloutnv.esm|deadmoney.esm|honesthearts.esm|oldworldblues.esm|lonesomeroad.esm|gunrunnersarsenal.esm|fallout3.esm|anchorage.esm|thepitt.esm|brokensteel.esm|pointlookout.esm|zeta.esm|caravanpack.esm|classicpack.esm|mercenarypack.esm|tribalpack.esm|taleoftwowastelands.esm|yupttw.esm|';

var
  Rows: TStringList;
  PlacedCounts: TStringList;
  LeveledCounts: TStringList;

function Csv(const Value: string): string;
begin
  Result := '"' + StringReplace(Value, '"', '""', [rfReplaceAll]) + '"';
end;

function BooleanText(Value: Boolean): string;
begin
  if Value then Result := 'true' else Result := 'false';
end;

procedure IncrementCount(Counts: TStringList; linked: IInterface);
var
  key: string;
begin
  if not Assigned(linked) then Exit;
  linked := MasterOrSelf(linked);
  if Signature(linked) <> 'CREA' then Exit;
  key := IntToHex(GetLoadOrderFormID(linked), 8);
  Counts.Values[key] := IntToStr(StrToIntDef(Counts.Values[key], 0) + 1);
end;

function IsOfficialFile(f: IInterface): Boolean;
begin
  Result := Pos('|' + LowerCase(GetFileName(f)) + '|', OfficialFiles) > 0;
end;

function LinkName(e: IInterface; const Path: string): string;
var
  linked: IInterface;
begin
  Result := '';
  linked := LinksTo(ElementByPath(e, Path));
  if Assigned(linked) then
    Result := EditorID(linked) + ' [' + Signature(linked) + ':' + IntToHex(GetLoadOrderFormID(linked), 8) + ']';
end;

function Initialize: Integer;
var
  i, j, k, refs, placedRefs, leveledRefs, dialogueRefs: Integer;
  f, group, recordElement, winning, defining, referenced, entries, entry: IInterface;
  definingPlugin, winningPlugin, flags, sex, templateName: string;
begin
  Result := 0;
  Rows := TStringList.Create;
  PlacedCounts := TStringList.Create;
  LeveledCounts := TStringList.Create;
  Rows.Add('stable_identity,defining_plugin,local_formid,runtime_formid,winning_plugin,editor_id,display_name,race,creature_type,sex,model,voice_type,template,placed_references,leveled_references,dialogue_references,total_references,deleted,initially_disabled');

  // Scan the authoritative winning ACHR and LVLC records directly. This avoids
  // relying on xEdit's optional/stale Referenced By cache for reachability.
  for i := 0 to Pred(FileCount) do begin
    f := FileByIndex(i);
    if not IsOfficialFile(f) then Continue;
    for j := 0 to Pred(RecordCount(f)) do begin
      recordElement := RecordByIndex(f, j);
      winning := WinningOverride(recordElement);
      if not Assigned(winning) or not IsOfficialFile(GetFile(winning)) then Continue;
      if not SameText(GetFileName(GetFile(winning)), GetFileName(f)) then Continue;
      if Signature(winning) = 'ACHR' then
        IncrementCount(PlacedCounts, LinksTo(ElementByPath(winning, 'NAME')))
      else if Signature(winning) = 'LVLC' then begin
        entries := ElementByPath(winning, 'Leveled List Entries');
        if not Assigned(entries) then Continue;
        for k := 0 to Pred(ElementCount(entries)) do begin
          entry := ElementByIndex(entries, k);
          IncrementCount(LeveledCounts, LinksTo(ElementByPath(entry, 'LVLO\Reference')));
        end;
      end;
    end;
  end;

  for i := 0 to Pred(FileCount) do begin
    f := FileByIndex(i);
    if not IsOfficialFile(f) then
      Continue;
    group := GroupBySignature(f, 'CREA');
    if not Assigned(group) then
      Continue;

    for j := 0 to Pred(ElementCount(group)) do begin
      recordElement := ElementByIndex(group, j);
      winning := WinningOverride(recordElement);
      if not Assigned(winning) then
        Continue;
      if not IsOfficialFile(GetFile(winning)) then
        Continue;
      defining := MasterOrSelf(winning);
      if not SameText(GetFileName(GetFile(defining)), GetFileName(f)) then
        Continue;

      definingPlugin := GetFileName(GetFile(defining));
      winningPlugin := GetFileName(GetFile(winning));
      refs := ReferencedByCount(defining);
      placedRefs := StrToIntDef(PlacedCounts.Values[IntToHex(GetLoadOrderFormID(defining), 8)], 0);
      leveledRefs := StrToIntDef(LeveledCounts.Values[IntToHex(GetLoadOrderFormID(defining), 8)], 0);
      dialogueRefs := 0;
      for k := 0 to Pred(refs) do begin
        referenced := ReferencedByIndex(defining, k);
        if Signature(referenced) = 'ACHR' then Inc(placedRefs);
        if Signature(referenced) = 'LVLC' then Inc(leveledRefs);
        if (Signature(referenced) = 'DIAL') or (Signature(referenced) = 'INFO') then Inc(dialogueRefs);
      end;

      flags := GetElementEditValues(winning, 'ACBS\Flags');
      if Pos('Female', flags) > 0 then sex := 'female' else sex := 'unknown';
      templateName := LinkName(winning, 'TPLT');

      Rows.Add(
        Csv(definingPlugin + '|' + IntToHex(GetLoadOrderFormID(defining) and $00FFFFFF, 8)) + ',' +
        Csv(definingPlugin) + ',' +
        Csv(IntToHex(GetLoadOrderFormID(defining) and $00FFFFFF, 8)) + ',' +
        Csv(IntToHex(GetLoadOrderFormID(winning), 8)) + ',' +
        Csv(winningPlugin) + ',' +
        Csv(EditorID(winning)) + ',' +
        Csv(GetElementEditValues(winning, 'FULL')) + ',' +
        Csv(LinkName(winning, 'RNAM')) + ',' +
        Csv(GetElementEditValues(winning, 'DATA\Creature Type')) + ',' +
        Csv(sex) + ',' +
        Csv(GetElementEditValues(winning, 'Model\MODL')) + ',' +
        Csv(LinkName(winning, 'VTCK')) + ',' +
        Csv(templateName) + ',' +
        IntToStr(placedRefs) + ',' + IntToStr(leveledRefs) + ',' + IntToStr(dialogueRefs) + ',' + IntToStr(refs) + ',' +
        Csv(BooleanText(GetIsDeleted(winning))) + ',' + Csv(BooleanText(GetIsInitiallyDisabled(winning)))
      );
    end;
  end;

  ForceDirectories(ProgramPath + 'tmp');
  Rows.SaveToFile(ProgramPath + OutputPath);
  AddMessage('Exported ' + IntToStr(Rows.Count - 1) + ' official winning CREA records to ' + ProgramPath + OutputPath);
end;

function Finalize: Integer;
begin
  Rows.Free;
  PlacedCounts.Free;
  LeveledCounts.Free;
  Result := 0;
end;

end.
