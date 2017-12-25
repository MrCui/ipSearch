#coding=gb18030
#Author@Cui
import socket 
import struct

# ģ��PHP ip2long;
def ip2long( ipstr ): 
    return struct.unpack( "!I", socket.inet_aton( ipstr ) )[0]

# ģ��PHP long2ip;
def long2ip( ip ): 
    return socket.inet_ntoa( struct.pack( "!I", ip ) )


# �������ݿ�;
def IpSearch( Ip ):

    try:
        ip = ip2long( Ip )
    except Exception, e :
        return False

    # �����ļ�;
    Path =  './qqwry.dat'
    ipData = open( Path )
    
    # ���
    S = struct.Struct('L4')
    
    # ��ʼ
    indexStart = ipData.read( 4 )
    indexStart = S.unpack( indexStart )[0]
    
    # ��β
    indexEnd = ipData.read( 4 )
    indexEnd = S.unpack( indexEnd )[0]
    
    # ����
    total = ( indexEnd - indexStart ) / 7 + 1

    # ƥ��IP
    searchStart = ipEnd = ipStart = offset  = 0
    searchEnd = total

    while ip < ipStart or ip > ipEnd :
        
        middle = ( searchStart + searchEnd ) / 2
        middle = int( middle )
        
        offset = indexStart + middle * 7
        ipData.seek( offset )
        ipStart = ipData.read( 4 )
        ipStart = S.unpack( ipStart )[0]
        
        if ip < ipStart :
            searchEnd = middle - 1
            continue

        offset = ipData.read( 3 )
        offset = offset + chr( 0 )
        offset = S.unpack( offset )[0]
        ipData.seek( offset )
        ipEnd = ipData.read( 4 )
        ipEnd = S.unpack( ipEnd )[0]
        
        if ip > ipEnd :
            if searchStart == middle : break ;
            searchStart = middle + 1
                
    if type( offset ) != int :
        return False

    # ��������

    ipData.seek( offset + 4 )
    mode = cityOffset = flag = False
    result = '';

    while True :
        mode = ipData.read( 1 )
        
        if mode == chr( 0x01 ) :
            offset = ipData.read( 3 )
            offset = offset + chr( 0 )
            offset = S.unpack( offset )[0]
            ipData.seek( offset )
            continue

        if mode == chr( 0x02 ) :
            offset = ipData.read( 3 )
            offset = offset + chr( 0 )
            offset = S.unpack( offset )[0]
            if cityOffset is False : cityOffset = ipData.tell();
            ipData.seek( offset )
            continue

        if mode == chr( 0x00 ) : 
            return False

        ipData.seek( -1, 1 )

        char = "";
        
        while char != chr( 0 ) :
            char = ipData.read( 1 )
            result += char;
        
        if cityOffset is False : cityOffset = ipData.tell();
        
        if flag is False :
            ipData.seek( cityOffset )
            result += " "
            flag = True
            continue

        break

    return result
    

while True :
    
    inputIp = raw_input( '������IP (����exit�ر�) : ' )
    
    if inputIp == 'exit' : break;

    outputInfo = IpSearch( inputIp )
    
    if outputInfo is False :
        print "δ�ҵ�!"
    else :
        print outputInfo

