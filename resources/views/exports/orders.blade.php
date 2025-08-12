<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            font-size: 12px;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #333;
            padding-bottom: 15px;
        }
        .logo-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }
        .logo {
            max-height: 50px;
            max-width: 120px;
            object-fit: contain;
            margin: 5px;
        }
        .company-info {
            margin: 10px 0;
        }
        .company-info h1 {
            margin: 0;
            color: #333;
            font-size: 20px;
        }
        .company-info h2 {
            margin: 5px 0;
            color: #666;
            font-size: 14px;
            font-weight: normal;
        }
        .company-info p {
            margin: 5px 0;
            color: #666;
            font-size: 11px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 10px;
        }
        th {
            background-color: #f8f9fa;
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
            font-weight: bold;
            color: #333;
        }
        td {
            border: 1px solid #ddd;
            padding: 6px;
            text-align: left;
            word-wrap: break-word;
            max-width: 150px;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 10px;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
        .export-info {
            margin-bottom: 15px;
            font-size: 11px;
        }
        .export-info div {
            margin: 3px 0;
        }
    </style>
</head>
<body>
    <div class="header">
        @if(!empty($orders['header_info']['logos']))
        <div class="logo-row">
            @foreach($orders['header_info']['logos'] as $logo)
                <img src="{{ $logo }}" class="logo" alt="Logo">
            @endforeach
        </div>
        @endif
        
        <div class="company-info">
            <h1>{{ $orders['header_info']['title'] }}</h1>
            @if(!empty($orders['header_info']['subtitle']))
                <h2>{{ $orders['header_info']['subtitle'] }}</h2>
            @endif
        </div>
        
        <div class="export-info">
            <div><strong>Fecha de exportación:</strong> {{ $orders['header_info']['export_date'] }}</div>
            <div><strong>{{ $orders['header_info']['date_range'] }}</strong></div>
            <div><strong>{{ $orders['header_info']['status_filter'] }}</strong></div>
        </div>
    </div>
    
    <table>
        <thead>
            <tr>
                @foreach($orders['headers'] as $header)
                    <th>{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($orders['orders'] as $order)
                <tr>
                    @foreach($order as $cell)
                        <td>{{ $cell ?? '' }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
    
    <div class="footer">
        <p>Este reporte contiene {{ count($orders['orders']) }} registros</p>
        <p>Generado el {{ $orders['header_info']['export_date'] }}</p>
    </div>
</body>
</html>