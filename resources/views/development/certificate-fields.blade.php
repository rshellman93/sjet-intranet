<label>Certifikatets navn<input name="title" value="{{ $certificate?->title }}" maxlength="190" required list="certificate-titles"></label>
<div class="form-grid"><label class="check"><input type="checkbox" name="never_expires" value="1" @checked($certificate?->never_expires)>Udløber ikke</label><label>Udløbsdato<input name="expires_on" type="date" value="{{ $certificate?->expires_on }}" @required(!$certificate?->never_expires) @disabled($certificate?->never_expires)></label></div>
<label>Note (valgfri)<textarea name="note" maxlength="2000">{{ $certificate?->note }}</textarea></label>
<label>PDF-bevis (valgfrit)<input type="file" name="certificate_file" accept="application/pdf,.pdf"><small>Højst 20 MB.</small></label>
