<h1>{{ $clientName }}｜{{ $reportTitle }}</h1>

<p>報表期間：{{ $periodLabel }}</p>

<p>這是由 {{ $brandName }} 自動寄送的定期成效報表。線上報表可查看各活動的成效與前期變化，附件為同一份資料的 CSV 封存。</p>

<p><a href="{{ $reportUrl }}">查看線上報表</a></p>

<p>線上報表連結將於 {{ $expiresAt->setTimezone(new DateTimeZone('Asia/Taipei'))->format('Y/n/j H:i') }}（台北時間）到期。請勿轉寄此郵件給不應查看報表的人。</p>
